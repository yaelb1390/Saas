<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application;

use App\Modules\Billing\Support\TaxId;
use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EmissionMode;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use App\Modules\ElectronicInvoicing\Providers\PsfeProvider;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;

/**
 * Los 8 pasos para emitir e-CF, con su estado CALCULADO de lo que ya existe (no guardado aparte: un
 * estado guardado se queda viejo en cuanto alguien cambia algo en otra tarjeta).
 *
 * Los pasos 7 y 8 dependen de la DGII (certificación y autorización): BMIA no puede saberlo, así que
 * se dan por hechos solo cuando el usuario cambia de ambiente, y pasar a producción exige su
 * confirmación expresa (ver UpdateElectronicInvoicingSettingsRequest).
 */
final class SetupWizard
{
    public function __construct(
        private readonly CertificateVault $certificates,
        private readonly Diagnostics $diagnostics,
        private readonly PsfeProvider $psfe,
    ) {}

    /**
     * @return list<array{n: int, title: string, detail: string, done: bool, anchor: string}>
     */
    public function steps(Company $company): array
    {
        $s = ElectronicInvoicingSettings::paraEmpresa($company);
        $cert = $this->certificates->active($company);

        $datos = TaxId::tryParse((string) $s->tax_id) !== null && filled($s->legal_name) && filled($s->address);
        $secuencias = ElectronicNcfSequence::query()->where('is_active', true)->where('environment', $s->environment->value)->exists();
        $certificado = $cert !== null && in_array($cert->status(), ['vigente', 'por_vencer'], true);
        $sinErrores = collect($this->diagnostics->checks($company, probarAlmacenamiento: false))
            ->whereNotIn('key', ['cron'])
            ->every(fn (array $c): bool => $c['level'] !== Diagnostics::ERROR);
        $aceptadoEnPruebas = ElectronicInvoice::query()
            ->whereIn('environment', [Environment::Pruebas->value, Environment::Certificacion->value])
            ->whereIn('status', [EcfStatus::Aceptado->value, EcfStatus::AceptadoCondicional->value])
            ->exists();
        $orden = [Environment::Pruebas, Environment::Certificacion, Environment::Produccion];
        $nivel = array_search($s->environment, $orden, true);

        $pasos = [
            [1, 'Datos fiscales', 'RNC, razón social y dirección del emisor.', $datos, 'datos'],
            [2, 'Secuencias de e-NCF', 'Los rangos que te autorizó la DGII, en el ambiente actual.', $secuencias, 'secuencias'],
            $this->pasoFirma($company, $s, $certificado),
            [4, 'Ambiente de pruebas', 'Emisión «en paralelo»: tus ventas generan e-CF de prueba sin tocar la serie B.', $s->emissionMode() !== EmissionMode::Apagado || $nivel > 0, 'emision'],
            [5, 'Validación técnica', 'El diagnóstico sin errores.', $sinErrores, 'diagnostico'],
            [6, 'Pruebas aceptadas', 'Al menos un e-CF aceptado en pruebas o certificación.', $aceptadoEnPruebas, 'documentos'],
            [7, 'Certificación DGII', 'Completa el proceso de certificación ante la DGII y pasa al ambiente de certificación.', $nivel >= 1, 'datos'],
            [8, 'Producción', 'Con la autorización de la DGII, pasa a producción y enciende «e-CF en lugar de la serie B».', $nivel === 2 && $s->emissionMode() === EmissionMode::Real, 'emision'],
        ];

        return array_map(fn (array $p): array => ['n' => $p[0], 'title' => $p[1], 'detail' => $p[2], 'done' => $p[3], 'anchor' => $p[4]], $pasos);
    }

    /**
     * Paso 3: con qué se firma. Con un proveedor certificado (PSFE) el paso es conectarlo; si además
     * firma por la empresa, el certificado deja de hacer falta. Sin PSFE, el certificado de siempre.
     * Mismo número de paso en los dos casos: el resto del asistente no cambia.
     *
     * @return array{0: int, 1: string, 2: string, 3: bool, 4: string}
     */
    private function pasoFirma(Company $company, ElectronicInvoicingSettings $s, bool $certificado): array
    {
        if ($s->provider !== 'psfe') {
            return [3, 'Certificado digital', 'El certificado para procesos tributarios con el que se firma.', $certificado, 'certificado'];
        }

        $conector = $this->psfe->driverFor($company);

        return match (true) {
            $conector === null => [3, 'Proveedor autorizado', 'Conecta tu proveedor certificado (PSFE) con los datos de tu cuenta.', false, 'proveedor'],
            $conector->capabilities()->signs => [3, 'Proveedor autorizado', "Conectado a {$conector->label()}: la firma la hace tu proveedor, no hace falta subir certificado.", true, 'proveedor'],
            default => [3, 'Proveedor y certificado', "Conectado a {$conector->label()}. Este proveedor no firma por ti: sube tu certificado digital.", $certificado, 'certificado'],
        };
    }
}
