<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application;

use App\Modules\Billing\Support\TaxId;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\ElectronicInvoicing\Contingency\ContingencyService;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\EmissionMode;
use App\Modules\ElectronicInvoicing\Models\ElectronicCertificate;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeConnectionList;
use App\Modules\ElectronicInvoicing\Providers\PsfeProvider;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Diagnóstico de la facturación electrónica de una empresa: cada chequeo dice CORRECTO, ADVERTENCIA o
 * ERROR y, si no está bien, cómo solucionarlo. Solo lee: nunca llama a la DGII.
 *
 * `alerts()` es el subconjunto urgente y barato (3 consultas) que va a la campana del panel.
 */
final class Diagnostics
{
    public const OK = 'ok';

    public const AVISO = 'aviso';

    public const ERROR = 'error';

    /** Un documento «enviando» más de esto se cortó a mitad (la función de Vercel dura ~10 s). */
    private const ATASCADO_MINUTOS = 10;

    /** Si hay pendientes y el procesador no corre desde hace más de esto, el cron externo no llama. */
    private const CRON_MINUTOS = 20;

    /** Números restantes por debajo de los cuales avisar (mismo criterio que la serie B). */
    private const POCOS_NUMEROS = 50;

    public function __construct(
        private readonly CertificateVault $certificates,
        private readonly SchemaRegistry $schemas,
        private readonly RuntimeRequirements $runtime,
        private readonly ContingencyService $contingency,
        private readonly PsfeProvider $psfe,
    ) {}

    /**
     * @return list<array{key: string, title: string, level: string, detail: string, fix: ?string}>
     */
    public function checks(Company $company, bool $probarAlmacenamiento = true): array
    {
        if (! DbTable::existe('electronic_invoicing_settings') || ! DbTable::existe('electronic_invoices')) {
            return [$this->item('migraciones', 'Base de datos', self::ERROR, 'Faltan las tablas de facturación electrónica.', 'El administrador de la plataforma debe aplicar las migraciones pendientes.')];
        }

        $s = ElectronicInvoicingSettings::paraEmpresa($company);
        $modo = $s->emissionMode();
        $emitiendo = $modo !== EmissionMode::Apagado;
        $r = [];

        // 1. Datos fiscales.
        $faltan = array_keys(array_filter([
            'RNC válido' => TaxId::tryParse((string) $s->tax_id) === null,
            'razón social' => blank($s->legal_name),
            'dirección' => blank($s->address),
        ]));
        $r[] = $faltan === []
            ? $this->item('datos', 'Datos fiscales del emisor', self::OK, "{$s->legal_name} · RNC {$s->tax_id}")
            : $this->item('datos', 'Datos fiscales del emisor', self::ERROR, 'Falta: '.implode(', ', $faltan).'.', 'Complétalos en «Datos fiscales y ambiente».');

        // 2. Certificado. Si el proveedor conectado firma por la empresa y no hay certificado, no es un
        // fallo: es algo que la empresa no tiene que hacer.
        $cert = $this->certificates->active($company);
        $conector = $s->provider === 'psfe' ? $this->psfe->driverFor($company) : null;
        $r[] = match (true) {
            $cert === null && $conector?->capabilities()->signs === true => $this->item('certificado', 'Firma digital', self::OK, "La hace tu proveedor ({$conector->label()}): no hace falta subir certificado."),
            default => $this->itemCertificado($cert, $emitiendo),
        };

        // 2b. [DTEE «Firmado de XML»] El SN del certificado tiene que ser el RNC/cédula del titular.
        if ($cert !== null) {
            preg_match('/serialNumber=([^,]+)/i', (string) $cert->subject, $m);
            $sn = preg_replace('/\D/', '', $m[1] ?? '');
            $rnc = preg_replace('/\D/', '', (string) $s->tax_id);

            $r[] = match (true) {
                $sn === '' => $this->item('certificado_sn', 'SN del certificado', self::AVISO, 'El certificado no trae SN (número de identificación del titular).', 'La DGII exige que el SN corresponda al RNC, cédula o pasaporte del titular: confírmalo con tu prestadora.'),
                $rnc !== '' && ! str_contains($sn, $rnc) => $this->item('certificado_sn', 'SN del certificado', self::AVISO, "El SN del certificado ({$sn}) no coincide con el RNC del emisor ({$rnc}).", 'La DGII exige que el SN corresponda al RNC, cédula o pasaporte del titular: revisa que sea el certificado de esta empresa.'),
                default => $this->item('certificado_sn', 'SN del certificado', self::OK, "Corresponde al RNC {$rnc}."),
            };
        }

        // 3. Ambiente, modo y proveedor.
        $r[] = $this->item('ambiente', 'Ambiente y emisión', self::OK, "{$s->environment->label()} · {$modo->label()}");

        // 3a. Los módulos de la EMPRESA, no los de quien mira: el Super Admin entra a esta pantalla
        // aunque la empresa no los tenga, y así parecía todo listo mientras ninguna venta generaba
        // su e-CF y nada lo decía.
        $sinModulo = array_values(array_filter(['billing', 'e_invoicing'], fn (string $m): bool => ! $company->hasModule($m)));
        if ($sinModulo !== []) {
            $nombres = implode(' y ', array_map([ModuleRegistry::class, 'label'], $sinModulo));
            $r[] = $this->item('modulos', 'Módulos de la empresa', $emitiendo ? self::ERROR : self::AVISO,
                "La empresa no tiene {$nombres}: ninguna venta generará su e-CF.",
                'Actívalos en Plataforma → Empresas → Módulos, o inclúyelos en su plan.');
        }
        // Con varios proveedores: el principal puede fallar y aun así haber un respaldo que funciona.
        $conexiones = $s->provider === 'psfe' ? PsfeConnectionList::read($s->provider_config) : [];
        $principalOk = ($conexiones[0]['check_ok'] ?? true) !== false;
        $algunoOk = collect($conexiones)->contains(fn (array $c): bool => ($c['check_ok'] ?? true) !== false);
        $respaldos = max(0, count($conexiones) - 1);
        $r[] = match (true) {
            $s->provider === 'psfe' && $conector === null => $this->item('proveedor', 'Proveedor', $emitiendo ? self::ERROR : self::AVISO, 'No hay ningún proveedor certificado (PSFE) conectado.', 'Conéctalo en «Proveedores autorizados».'),
            $s->provider === 'psfe' && ! $algunoOk => $this->item('proveedor', 'Proveedor', self::AVISO, 'La última prueba de conexión falló en todos tus proveedores.', 'Pulsa «Probar otra vez» o revisa los datos de tus cuentas.'),
            $s->provider === 'psfe' && ! $principalOk => $this->item('proveedor', 'Proveedor', self::AVISO, "La última prueba de {$conector->label()} (principal) falló: las facturas saldrán por el respaldo.", 'Revisa los datos de tu cuenta del principal o cambia el orden.'),
            $s->provider === 'psfe' => $this->item('proveedor', 'Proveedor', self::OK, $conector->label().' (principal)'.($respaldos > 0 ? " · {$respaldos} ".($respaldos === 1 ? 'respaldo' : 'respaldos') : ' · sin respaldo')),
            $s->provider === 'fake' && $emitiendo => $this->item('proveedor', 'Proveedor', self::AVISO, 'Proveedor de prueba: no se envía nada a la DGII.', 'Para enviar de verdad elige un proveedor real.'),
            default => $this->item('proveedor', 'Proveedor', self::OK, $s->provider),
        };

        // 4. Secuencias del ambiente actual (las que usa la emisión desde ventas: 31, 32, 34).
        $secuencias = ElectronicNcfSequence::query()->where('environment', $s->environment->value)->where('is_active', true)->get();
        foreach ([EcfType::CreditoFiscal, EcfType::Consumo, EcfType::NotaCredito] as $tipo) {
            $vivas = $secuencias->filter(fn ($q) => $q->ecf_type === $tipo && ($q->expires_at === null || $q->expires_at->isFuture()));
            $quedan = (int) $vivas->sum(fn ($q) => max(0, $q->range_to - $q->next_number + 1));

            $r[] = match (true) {
                $vivas->isEmpty() => $this->item("secuencia_{$tipo->value}", "Secuencia e-NCF {$tipo->value}", $emitiendo ? self::ERROR : self::AVISO, "No hay una secuencia vigente de {$tipo->label()} en {$s->environment->label()}.", 'Regístrala en «Secuencias de e-NCF» con el rango que te autorizó la DGII.'),
                $quedan <= self::POCOS_NUMEROS => $this->item("secuencia_{$tipo->value}", "Secuencia e-NCF {$tipo->value}", self::AVISO, "Quedan {$quedan} números.", 'Solicita un nuevo rango en la Oficina Virtual de la DGII y regístralo.'),
                default => $this->item("secuencia_{$tipo->value}", "Secuencia e-NCF {$tipo->value}", self::OK, "Quedan {$quedan} números."),
            };
        }

        // 5. Documentos con problemas.
        $estado = $this->resumen((int) $company->id);
        $r[] = $estado['atascados'] > 0
            ? $this->item('atascados', 'Envíos interrumpidos', self::ERROR, "{$estado['atascados']} documento(s) llevan más de ".self::ATASCADO_MINUTOS.' min en «Enviando».', 'Ábrelos en Documentos y consulta su resultado antes de reenviarlos: pudieron llegar a la DGII.')
            : $this->item('atascados', 'Envíos interrumpidos', self::OK, 'Ninguno.');
        $r[] = $estado['errores'] > 0
            ? $this->item('errores', 'Documentos con error', self::ERROR, "{$estado['errores']} documento(s) en error.", 'Ábrelos en Documentos para ver el motivo y reintentar.')
            : $this->item('errores', 'Documentos con error', self::OK, 'Ninguno.');
        $r[] = $estado['rechazados'] > 0
            ? $this->item('rechazados', 'Rechazados (7 días)', self::AVISO, "{$estado['rechazados']} documento(s) rechazados por la DGII.", 'Revisa el motivo en cada documento y emite uno nuevo corregido.')
            : $this->item('rechazados', 'Rechazados (7 días)', self::OK, 'Ninguno.');

        // 5b. En paralelo, un e-CF de prueba que falla NO para la venta (a propósito) y solo queda
        // anotado en Monitoreo, donde el dueño no mira. Aquí se cuentan las facturas B recientes que se
        // quedaron sin su e-CF y se da el último motivo anotado.
        if ($modo === EmissionMode::Sombra) {
            $r[] = $this->sombraSinEcf((int) $company->id);
        }

        // 6. Contingencia [IT §19]: enviar en 72 h tras volver la conexión.
        $abierta = $this->contingency->open((int) $company->id, $s->environment);
        $limite = (int) config('ecf.contingency.send_within_hours_after_connectivity', 72);
        $r[] = $abierta === null
            ? $this->item('contingencia', 'Contingencia', self::OK, 'Sin contingencia abierta.')
            : $this->item('contingencia', 'Contingencia', $this->contingency->hoursOpen($abierta) >= $limite ? self::ERROR : self::AVISO,
                'Abierta desde el '.$abierta->started_at->format('d/m/Y H:i').' ('.$this->contingency->hoursOpen($abierta).' h).',
                "Los documentos pendientes se reenvían solos al volver la conexión; la norma da {$limite} h.");

        // 7. Procesador de pendientes (cron externo cada 5 min).
        $ultimo = $this->ultimaCorrida();
        $r[] = match (true) {
            $estado['pendientes'] > 0 && ($ultimo === null || $ultimo->diffInMinutes(now()) > self::CRON_MINUTOS) => $this->item('cron', 'Procesador de pendientes', self::ERROR,
                'Hay documentos pendientes y el procesador no corre '.($ultimo ? 'desde '.$ultimo->diffForHumans() : 'nunca').'.',
                'Configura el llamador externo (cron-job.org) para /tareas/ecf-procesar cada 5 minutos.'),
            $ultimo === null => $this->item('cron', 'Procesador de pendientes', $emitiendo ? self::AVISO : self::OK, 'Todavía no ha corrido nunca.', $emitiendo ? 'Configura el llamador externo (cron-job.org) para /tareas/ecf-procesar cada 5 minutos.' : null),
            default => $this->item('cron', 'Procesador de pendientes', self::OK, 'Última corrida '.$ultimo->diffForHumans().'.'),
        };

        // 8. Esquemas oficiales, extensiones de PHP y almacenamiento.
        $r[] = $this->schemas->allIntact()
            ? $this->item('xsd', 'Esquemas oficiales (XSD)', self::OK, 'Íntegros (coinciden con su huella).')
            : $this->item('xsd', 'Esquemas oficiales (XSD)', self::ERROR, 'Algún XSD no coincide con su huella.', 'Restaura resources/dgii/ecf desde el repositorio.');
        $faltan = array_column(array_filter($this->runtime->check(), fn (array $x): bool => ! $x['ok']), 'extension');
        $r[] = $faltan === []
            ? $this->item('php', 'Requisitos del servidor', self::OK, 'Extensiones de PHP disponibles.')
            : $this->item('php', 'Requisitos del servidor', self::ERROR, 'Faltan extensiones: '.implode(', ', $faltan).'.', 'Avisa al administrador de la plataforma.');
        // Escribe y borra un archivo en el disco privado (S3 en producción): solo en la pantalla de
        // diagnóstico, no en cada visita al resumen.
        if ($probarAlmacenamiento) {
            $r[] = $this->almacenamiento();
        }

        return $r;
    }

    /**
     * Lo urgente para la campana: errores, envíos interrumpidos, contingencia abierta y certificado
     * por vencer o vencido.
     *
     * UNA consulta y sin preguntar al catálogo: la campana se pinta en todas las páginas y cada
     * consulta se paga en cada carga en frío (QueryBudgetTest). Si las tablas aún no existen (código
     * antes que migración), la consulta falla, se captura y no hay aviso.
     *
     * @return array{total: int, detalle: array<string, int>}
     */
    public function alerts(Company $company): array
    {
        $aviso = now()->addDays((int) config('ecf.certificate_warning_days', 30));

        try {
            $fila = DB::selectOne(
                'select
                    (select count(*) from electronic_invoices where company_id = ? and status = ?) as errores,
                    (select count(*) from electronic_invoices where company_id = ? and status = ? and updated_at < ?) as atascados,
                    (select count(*) from electronic_invoice_contingencies where company_id = ? and ended_at is null) as contingencia,
                    (select count(*) from electronic_certificates where company_id = ? and is_active = ? and valid_to <= ?) as certificado',
                [
                    $company->id, EcfStatus::Error->value,
                    $company->id, EcfStatus::Enviando->value, now()->subMinutes(self::ATASCADO_MINUTOS),
                    $company->id,
                    $company->id, true, $aviso,
                ],
            );
        } catch (Throwable) {
            return ['total' => 0, 'detalle' => []];
        }

        $detalle = array_filter([
            'errores' => (int) ($fila->errores ?? 0),
            'atascados' => (int) ($fila->atascados ?? 0),
            'contingencia' => (int) ($fila->contingencia ?? 0),
            'certificado' => min(1, (int) ($fila->certificado ?? 0)),
        ]);

        return ['total' => array_sum($detalle), 'detalle' => $detalle];
    }

    /** @return array{pendientes: int, errores: int, atascados: int, rechazados: int} */
    private function resumen(int $companyId): array
    {
        $fila = ElectronicInvoice::query()
            ->where('company_id', $companyId)
            ->selectRaw('sum(case when status in (?, ?, ?) then 1 else 0 end) as pendientes', [EcfStatus::PendienteEnvio->value, EcfStatus::Contingencia->value, EcfStatus::Recibido->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as errores', [EcfStatus::Error->value])
            ->selectRaw('sum(case when status = ? and updated_at < ? then 1 else 0 end) as atascados', [EcfStatus::Enviando->value, now()->subMinutes(self::ATASCADO_MINUTOS)])
            ->selectRaw('sum(case when status = ? and updated_at >= ? then 1 else 0 end) as rechazados', [EcfStatus::Rechazado->value, now()->subDays(7)])
            ->first();

        return [
            'pendientes' => (int) ($fila->pendientes ?? 0),
            'errores' => (int) ($fila->errores ?? 0),
            'atascados' => (int) ($fila->atascados ?? 0),
            'rechazados' => (int) ($fila->rechazados ?? 0),
        ];
    }

    private function ultimaCorrida(): ?\Illuminate\Support\Carbon
    {
        $fecha = SystemEvent::query()->withoutGlobalScopes()
            ->where('type', 'task.run')
            ->where('message', 'like', 'Envío y consulta de e-CF%')
            ->latest('id')
            ->value('created_at');

        return $fecha === null ? null : \Illuminate\Support\Carbon::parse($fecha);
    }

    /** @return array{key: string, title: string, level: string, detail: string, fix: ?string} */
    private function sombraSinEcf(int $companyId): array
    {
        $titulo = 'e-CF de prueba (7 días)';

        try {
            if (! DbTable::tieneColumna('invoices', 'electronic_invoice_id')) {
                return $this->item('sombra', $titulo, self::OK, 'Sin datos todavía.');
            }

            $sinEcf = DB::table('invoices')
                ->where('company_id', $companyId)
                ->whereIn('type', ['B01', 'B02', 'B15'])
                ->whereNull('electronic_invoice_id')
                ->where('issued_at', '>=', now()->subDays(7))
                ->count();

            if ($sinEcf === 0) {
                return $this->item('sombra', $titulo, self::OK, 'Cada factura B tiene su e-CF de prueba.');
            }

            $contexto = DbTable::existe('system_events')
                ? DB::table('system_events')->where('company_id', $companyId)->where('type', 'ecf.shadow_failed')
                    ->where('created_at', '>=', now()->subDays(7))->latest('created_at')->value('context')
                : null;
            $motivo = is_string($contexto) ? (json_decode($contexto, true)['motivo'] ?? null) : null;

            return $this->item('sombra', $titulo, self::AVISO,
                "{$sinEcf} factura(s) B de los últimos 7 días no tienen su e-CF de prueba."
                    .($motivo !== null ? " Último motivo: {$motivo}" : ' No quedó anotado ningún motivo.'),
                $motivo !== null ? 'Corrige lo que indica el motivo; las ventas siguientes ya generarán su e-CF.' : 'Avisa a soporte con la hora de la venta.');
        } catch (Throwable) {
            return $this->item('sombra', $titulo, self::AVISO, 'No se pudo comprobar.', null);
        }
    }

    /** El disco privado de documentos fiscales acepta escribir y leer. */
    private function almacenamiento(): array
    {
        try {
            $disco = CertificateVault::disk();
            $ruta = 'ecf/.diagnostico-'.bin2hex(random_bytes(4));
            $disco->put($ruta, 'ok', ['throw' => true]);
            $ok = $disco->get($ruta) === 'ok';
            $disco->delete($ruta);
        } catch (Throwable $e) {
            return $this->item('almacenamiento', 'Almacenamiento de documentos', self::ERROR, 'No se puede escribir: '.$e->getMessage(), 'Revisa FISCAL_DOCUMENTS_DISK y sus credenciales.');
        }

        return $ok
            ? $this->item('almacenamiento', 'Almacenamiento de documentos', self::OK, 'Escribe y lee correctamente.')
            : $this->item('almacenamiento', 'Almacenamiento de documentos', self::ERROR, 'Lo escrito no se lee igual.', 'Revisa FISCAL_DOCUMENTS_DISK.');
    }

    /** @return array{key: string, title: string, level: string, detail: string, fix: ?string} */
    private function item(string $key, string $title, string $level, string $detail, ?string $fix = null): array
    {
        return ['key' => $key, 'title' => $title, 'level' => $level, 'detail' => $detail, 'fix' => $level === self::OK ? null : $fix];
    }

    /**
     * El chequeo del certificado de la empresa (cuando firma BMIA).
     *
     * @return array{key: string, title: string, level: string, detail: string, fix: ?string}
     */
    private function itemCertificado(?ElectronicCertificate $cert, bool $emitiendo): array
    {
        return match ($cert?->status()) {
            'vigente' => $this->item('certificado', 'Certificado digital', self::OK, 'Vigente hasta el '.$cert->valid_to->format('d/m/Y').'.'),
            'por_vencer' => $this->item('certificado', 'Certificado digital', self::AVISO, 'Vence el '.$cert->valid_to->format('d/m/Y').'.', 'Renuévalo con tu prestadora de servicios de confianza y súbelo antes de que venza: sin él no se firma nada.'),
            'vencido' => $this->item('certificado', 'Certificado digital', self::ERROR, 'Venció el '.$cert->valid_to->format('d/m/Y').'.', 'Sube un certificado vigente.'),
            'aun_no_valido' => $this->item('certificado', 'Certificado digital', self::AVISO, 'Todavía no es válido.', 'Espera a su fecha de inicio o sube otro.'),
            default => $this->item('certificado', 'Certificado digital', $emitiendo ? self::ERROR : self::AVISO, 'No hay certificado cargado.', 'Súbelo en «Certificado digital».'),
        };
    }
}
