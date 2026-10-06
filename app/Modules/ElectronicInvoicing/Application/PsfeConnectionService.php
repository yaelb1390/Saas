<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Support\DbTable;
use App\Modules\ElectronicInvoicing\Domain\EmissionMode;
use App\Modules\ElectronicInvoicing\Events\PsfeConnected;
use App\Modules\ElectronicInvoicing\Events\PsfeDisconnected;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Providers\Psfe\ConnectionCheck;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeCatalog;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeConnectionList;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeDriver;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Los proveedores certificados (PSFE) de una empresa: conectar, probar, ordenar y desconectar. Es lo
 * que hay detrás de la tarjeta «Proveedores autorizados».
 *
 * Una empresa puede tener VARIOS, en orden: el primero es el principal y los demás, respaldos que se
 * usan si el anterior falla (ver `PsfeProvider`).
 *
 * Regla principal: NO se guarda una conexión que no funciona. Se prueba en vivo con el proveedor y
 * solo si acepta las credenciales se guardan (cifradas, en `provider_config`). Una clave mala no deja
 * a nadie a medio configurar ni desordena las demás conexiones.
 *
 * Las claves secretas no vuelven a salir de aquí: al reconectar el mismo proveedor, un campo secreto
 * vacío conserva el valor guardado.
 */
final class PsfeConnectionService
{
    public function __construct(
        private readonly PsfeCatalog $catalog,
        private readonly SigningReadiness $signing,
    ) {}

    /**
     * Las conexiones de la empresa para la pantalla, en su orden y sin credenciales. Las que tienen
     * un conector que ya no está en el catálogo no se enseñan.
     *
     * @return list<array{driver: PsfeDriver, slug: string, account: ?string, connected_at: ?string, checked_at: ?string, check_ok: bool, check_message: ?string}>
     */
    public function current(Company $company): array
    {
        $ajustes = ElectronicInvoicingSettings::paraEmpresa($company);

        if ($ajustes->provider !== 'psfe') {
            return [];
        }

        $vista = [];

        foreach (PsfeConnectionList::read($ajustes->provider_config) as $c) {
            $conector = $this->catalog->find((string) $c['psfe']);

            if ($conector !== null) {
                $vista[] = [
                    'driver' => $conector,
                    'slug' => (string) $c['psfe'],
                    'account' => $c['account'] ?? null,
                    'connected_at' => $c['connected_at'] ?? null,
                    'checked_at' => $c['checked_at'] ?? null,
                    'check_ok' => (bool) ($c['check_ok'] ?? false),
                    'check_message' => $c['check_message'] ?? null,
                ];
            }
        }

        return $vista;
    }

    /**
     * Prueba y, si funciona, guarda. Un proveedor nuevo entra al FINAL (respaldo); uno ya conectado
     * conserva su puesto y solo cambia sus datos. Devuelve el resultado de la prueba: si no salió
     * bien no se guardó nada.
     *
     * @param  array<string, mixed>  $input
     */
    public function connect(Company $company, string $slug, #[SensitiveParameter] array $input, ?int $userId = null): ConnectionCheck
    {
        $ajustes = ElectronicInvoicingSettings::paraEmpresa($company);
        $conector = $this->catalog->find($slug);

        if ($conector === null || ! $conector->availableIn($ajustes->environment)) {
            throw new RuntimeException('Ese proveedor no está disponible en el ambiente '.$ajustes->environment->label().'.');
        }

        // Si la empresa venía de otro proveedor (prueba o DGII directo), sus conexiones viejas no cuentan.
        $lista = $ajustes->provider === 'psfe' ? PsfeConnectionList::read($ajustes->provider_config) : [];
        $puesto = PsfeConnectionList::indexOf($lista, $slug);
        $guardadas = $puesto !== null ? (array) ($lista[$puesto]['credentials'] ?? []) : [];
        $credenciales = [];

        foreach ($conector->fields() as $campo) {
            $valor = trim((string) ($input[$campo->name] ?? ''));

            if ($valor === '' && $campo->secret) {
                $valor = (string) ($guardadas[$campo->name] ?? '');
            }

            if ($valor === '' && $campo->required) {
                throw new RuntimeException("Falta «{$campo->label}».");
            }

            if ($valor !== '') {
                $credenciales[$campo->name] = $valor;
            }
        }

        $prueba = $this->probarCon($company, $conector, $credenciales, $ajustes);

        if (! $prueba->ok) {
            return $prueba;
        }

        $ahora = now()->toIso8601String();
        $conexion = [
            'psfe' => $slug,
            'credentials' => $credenciales,
            'account' => $prueba->account,
            'connected_at' => $puesto !== null ? ($lista[$puesto]['connected_at'] ?? $ahora) : $ahora,
            'checked_at' => $ahora,
            'check_ok' => true,
            'check_message' => $prueba->message,
        ];

        if ($puesto !== null) {
            $lista[$puesto] = $conexion;
        } else {
            $lista[] = $conexion;
        }

        $ajustes->forceFill(['provider' => 'psfe', 'provider_config' => PsfeConnectionList::write($lista)])->save();
        $ajustes->syncStatus($this->signing->canSign($company));

        PsfeConnected::dispatch((int) $company->id, $slug, $ajustes->environment, $userId);

        return $prueba;
    }

    /** «Probar otra vez» una conexión: con lo guardado, y apunta el resultado para la pantalla y el diagnóstico. */
    public function test(Company $company, string $slug): ConnectionCheck
    {
        $ajustes = ElectronicInvoicingSettings::paraEmpresa($company);
        $lista = $ajustes->provider === 'psfe' ? PsfeConnectionList::read($ajustes->provider_config) : [];
        $puesto = PsfeConnectionList::indexOf($lista, $slug);
        $conector = $this->catalog->find($slug);

        if ($puesto === null || $conector === null) {
            throw new RuntimeException('Ese proveedor no está conectado.');
        }

        $prueba = $this->probarCon($company, $conector, (array) ($lista[$puesto]['credentials'] ?? []), $ajustes);

        $lista[$puesto] = array_merge($lista[$puesto], [
            'checked_at' => now()->toIso8601String(),
            'check_ok' => $prueba->ok,
            'check_message' => $prueba->message,
            'account' => $prueba->account ?? ($lista[$puesto]['account'] ?? null),
        ]);

        $ajustes->forceFill(['provider_config' => PsfeConnectionList::write($lista)])->save();

        return $prueba;
    }

    /** Sube una conexión un puesto (hasta principal). */
    public function raise(Company $company, string $slug): void
    {
        $ajustes = ElectronicInvoicingSettings::paraEmpresa($company);
        $lista = PsfeConnectionList::read($ajustes->provider_config);
        $puesto = PsfeConnectionList::indexOf($lista, $slug);

        if ($puesto === null) {
            throw new RuntimeException('Ese proveedor no está conectado.');
        }

        if ($puesto > 0) {
            [$lista[$puesto - 1], $lista[$puesto]] = [$lista[$puesto], $lista[$puesto - 1]];
            $ajustes->forceFill(['provider_config' => PsfeConnectionList::write($lista)])->save();
        }
    }

    /**
     * Quita una conexión y sus credenciales. Si era la última: en pruebas vuelve al proveedor de
     * prueba; en certificación/producción, donde ese no vale, queda el PSFE «sin conectar» a la vista.
     *
     * Si ya no queda con qué firmar, la emisión se APAGA: seguir encendida solo dejaría cada factura
     * con un e-CF en error.
     *
     * @return bool si se apagó la emisión
     */
    public function disconnect(Company $company, string $slug, ?int $userId = null): bool
    {
        $ajustes = ElectronicInvoicingSettings::paraEmpresa($company);
        $lista = PsfeConnectionList::read($ajustes->provider_config);
        $puesto = PsfeConnectionList::indexOf($lista, $slug);

        if ($puesto !== null) {
            array_splice($lista, $puesto, 1);
        }

        $ajustes->forceFill([
            'provider' => $lista === [] && ! $ajustes->environment->isFiscal() ? 'fake' : 'psfe',
            'provider_config' => PsfeConnectionList::write($lista),
        ])->save();

        $apagada = false;
        if (DbTable::tieneColumna('electronic_invoicing_settings', 'emission_mode')
            && $ajustes->emissionMode() !== EmissionMode::Apagado
            && ! $this->signing->canSign($company)) {
            $ajustes->forceFill(['emission_mode' => EmissionMode::Apagado->value])->save();
            $apagada = true;
        }

        $ajustes->syncStatus($this->signing->canSign($company));

        PsfeDisconnected::dispatch((int) $company->id, $slug, $apagada, $userId);

        return $apagada;
    }

    /**
     * La prueba en vivo. Un fallo inesperado del conector (red, respuesta ilegible) se cuenta como
     * prueba fallida con su motivo, no como un error de la pantalla.
     *
     * @param  array<string, string>  $credenciales
     */
    private function probarCon(Company $company, PsfeDriver $conector, #[SensitiveParameter] array $credenciales, ElectronicInvoicingSettings $ajustes): ConnectionCheck
    {
        try {
            return $conector->testConnection($company, $credenciales, $ajustes->environment);
        } catch (Throwable $e) {
            report($e);

            return new ConnectionCheck(false, "No se pudo comunicar con {$conector->label()}. Inténtalo de nuevo en unos minutos.");
        }
    }
}
