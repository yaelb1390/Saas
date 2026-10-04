<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application;

use App\Modules\Billing\Support\TaxId;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Support\DbTable;
use App\Modules\ElectronicInvoicing\Contingency\ContingencyService;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\EmissionMode;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
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
    ) {}

    /**
     * @return list<array{key: string, title: string, level: string, detail: string, fix: ?string}>
     */
    public function checks(Company $company): array
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

        // 2. Certificado.
        $cert = $this->certificates->active($company);
        $r[] = match ($cert?->status()) {
            'vigente' => $this->item('certificado', 'Certificado digital', self::OK, 'Vigente hasta el '.$cert->valid_to->format('d/m/Y').'.'),
            'por_vencer' => $this->item('certificado', 'Certificado digital', self::AVISO, 'Vence el '.$cert->valid_to->format('d/m/Y').'.', 'Renuévalo con tu prestadora de servicios de confianza y súbelo antes de que venza: sin él no se firma nada.'),
            'vencido' => $this->item('certificado', 'Certificado digital', self::ERROR, 'Venció el '.$cert->valid_to->format('d/m/Y').'.', 'Sube un certificado vigente.'),
            'aun_no_valido' => $this->item('certificado', 'Certificado digital', self::AVISO, 'Todavía no es válido.', 'Espera a su fecha de inicio o sube otro.'),
            default => $this->item('certificado', 'Certificado digital', $emitiendo ? self::ERROR : self::AVISO, 'No hay certificado cargado.', 'Súbelo en «Certificado digital».'),
        };

        // 3. Ambiente, modo y proveedor.
        $r[] = $this->item('ambiente', 'Ambiente y emisión', self::OK, "{$s->environment->label()} · {$modo->label()}");
        $r[] = match (true) {
            $s->provider === 'psfe' && $emitiendo => $this->item('proveedor', 'Proveedor', self::ERROR, 'El proveedor certificado (PSFE) todavía no está conectado en BMIA.', 'Elige el proveedor o usa el de prueba mientras tanto.'),
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
        $r[] = $this->almacenamiento();

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
}
