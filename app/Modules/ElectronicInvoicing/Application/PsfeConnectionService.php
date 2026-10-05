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
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeDriver;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Conectar, probar y desconectar el proveedor certificado (PSFE) de una empresa: lo que hay detrás
 * de la tarjeta «Conecta tu proveedor autorizado».
 *
 * Regla principal: NO se guarda una conexión que no funciona. Se prueba en vivo con el proveedor y
 * solo si acepta las credenciales se guardan (cifradas, en `provider_config`) y la empresa pasa a
 * usar ese proveedor. Una clave mala no deja a nadie a medio configurar.
 *
 * Las claves secretas no vuelven a salir de aquí: al reconectar con el mismo proveedor, un campo
 * secreto vacío conserva el valor guardado.
 */
final class PsfeConnectionService
{
    public function __construct(
        private readonly PsfeCatalog $catalog,
        private readonly SigningReadiness $signing,
    ) {}

    /**
     * Lo que la pantalla enseña de la conexión actual (sin credenciales), o null si no hay.
     *
     * @return array{driver: PsfeDriver, account: ?string, connected_at: ?string, checked_at: ?string, check_ok: bool, check_message: ?string}|null
     */
    public function current(Company $company): ?array
    {
        $ajustes = ElectronicInvoicingSettings::paraEmpresa($company);
        $config = (array) ($ajustes->provider_config ?? []);
        $conector = $ajustes->provider === 'psfe' ? $this->catalog->find($config['psfe'] ?? null) : null;

        if ($conector === null) {
            return null;
        }

        return [
            'driver' => $conector,
            'account' => $config['account'] ?? null,
            'connected_at' => $config['connected_at'] ?? null,
            'checked_at' => $config['checked_at'] ?? null,
            'check_ok' => (bool) ($config['check_ok'] ?? false),
            'check_message' => $config['check_message'] ?? null,
        ];
    }

    /**
     * Prueba y, si funciona, guarda. Devuelve el resultado de la prueba: si no salió bien no se
     * guardó nada.
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

        $anterior = (array) ($ajustes->provider_config ?? []);
        $guardadas = ($anterior['psfe'] ?? null) === $slug ? (array) ($anterior['credentials'] ?? []) : [];
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

        $prueba = $this->probarCon($conector, $credenciales, $ajustes);

        if (! $prueba->ok) {
            return $prueba;
        }

        $ahora = now()->toIso8601String();
        $ajustes->forceFill([
            'provider' => 'psfe',
            'provider_config' => [
                'psfe' => $slug,
                'credentials' => $credenciales,
                'account' => $prueba->account,
                'connected_at' => $ahora,
                'checked_at' => $ahora,
                'check_ok' => true,
                'check_message' => $prueba->message,
            ],
        ])->save();

        $ajustes->syncStatus($this->signing->canSign($company));

        PsfeConnected::dispatch((int) $company->id, $slug, $ajustes->environment, $userId);

        return $prueba;
    }

    /** «Probar otra vez»: con lo guardado, y apunta el resultado para la pantalla y el diagnóstico. */
    public function test(Company $company): ConnectionCheck
    {
        $ajustes = ElectronicInvoicingSettings::paraEmpresa($company);
        $config = (array) ($ajustes->provider_config ?? []);
        $conector = $ajustes->provider === 'psfe' ? $this->catalog->find($config['psfe'] ?? null) : null;

        if ($conector === null) {
            throw new RuntimeException('No hay un proveedor conectado.');
        }

        $prueba = $this->probarCon($conector, (array) ($config['credentials'] ?? []), $ajustes);

        $ajustes->forceFill(['provider_config' => array_merge($config, [
            'checked_at' => now()->toIso8601String(),
            'check_ok' => $prueba->ok,
            'check_message' => $prueba->message,
            'account' => $prueba->account ?? ($config['account'] ?? null),
        ])])->save();

        return $prueba;
    }

    /**
     * Quita la conexión y las credenciales. En pruebas vuelve al proveedor de prueba; en
     * certificación/producción, donde ese no vale, queda el PSFE «sin conectar» a la vista.
     *
     * Si ya no queda con qué firmar, la emisión se APAGA: seguir encendida solo dejaría cada factura
     * con un e-CF en error.
     *
     * @return bool si se apagó la emisión
     */
    public function disconnect(Company $company, ?int $userId = null): bool
    {
        $ajustes = ElectronicInvoicingSettings::paraEmpresa($company);
        $slug = (string) (((array) ($ajustes->provider_config ?? []))['psfe'] ?? '');

        $ajustes->forceFill([
            'provider' => $ajustes->environment->isFiscal() ? 'psfe' : 'fake',
            'provider_config' => null,
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
    private function probarCon(PsfeDriver $conector, #[SensitiveParameter] array $credenciales, ElectronicInvoicingSettings $ajustes): ConnectionCheck
    {
        try {
            return $conector->testConnection($credenciales, $ajustes->environment);
        } catch (Throwable $e) {
            report($e);

            return new ConnectionCheck(false, "No se pudo comunicar con {$conector->label()}. Inténtalo de nuevo en unos minutos.");
        }
    }
}
