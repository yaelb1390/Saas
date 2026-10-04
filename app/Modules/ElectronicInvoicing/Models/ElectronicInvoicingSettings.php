<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Models;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\ElectronicInvoicing\Domain\EmissionMode;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Domain\SetupStatus;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Configuración de facturación electrónica de UNA empresa. Nunca se comparte entre empresas.
 *
 * @property string|null $tax_id
 * @property string|null $legal_name
 * @property string|null $trade_name
 * @property Environment $environment
 * @property SetupStatus $status
 * @property string $provider
 * @property array<string, mixed>|null $provider_config
 * @property \Illuminate\Support\Carbon|null $last_dgii_contact_at
 */
final class ElectronicInvoicingSettings extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;

    protected $table = 'electronic_invoicing_settings';

    protected $fillable = [
        'company_id', 'tax_id', 'legal_name', 'trade_name', 'address', 'municipality', 'province',
        'phone', 'email', 'environment', 'ecf_admin_user',
    ];

    /**
     * Las credenciales del proveedor nunca van a la auditoría: quedarían en claro en otra tabla.
     *
     * @var array<int, string>
     */
    protected $auditExclude = ['provider_config'];

    /**
     * La fila de una empresa, creándola si no existe.
     *
     * Nace en pruebas, sin configurar y con el proveedor de prueba (no envía nada a ningún sitio).
     * Los datos fiscales se precargan desde «Mi empresa» para no pedir dos veces lo mismo; el
     * usuario los revisa en el paso de datos fiscales.
     */
    public static function paraEmpresa(Company $company): self
    {
        $ajustes = self::withoutGlobalScopes()->where('company_id', $company->id)->first();

        if ($ajustes !== null) {
            return $ajustes;
        }

        $ajustes = new self;

        $ajustes->forceFill([
            'company_id' => $company->id,
            'tax_id' => self::soloDigitos($company->tax_id),
            'legal_name' => $company->legal_name,
            'trade_name' => $company->name,
            'address' => $company->address,
            'phone' => $company->phone,
            'email' => $company->email,
            'environment' => Environment::Pruebas,
            'status' => SetupStatus::NoConfigurado,
            'provider' => (string) config('ecf.default_provider', 'fake'),
            'spec_version' => (string) config('ecf.spec.version', '1.0'),
        ])->save();

        return $ajustes;
    }

    /**
     * Modo de emisión. Sin la columna (código antes que migración) o con un valor que no cuadra con
     * el ambiente, «apagado»: nunca se emite un e-CF por un estado a medias.
     */
    public function emissionMode(): EmissionMode
    {
        $modo = EmissionMode::tryFrom((string) ($this->getAttributes()['emission_mode'] ?? '')) ?? EmissionMode::Apagado;

        return $modo->allowedIn($this->environment) ? $modo : EmissionMode::Apagado;
    }

    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'status' => SetupStatus::class,
            'provider_config' => 'encrypted:array',
            'last_dgii_contact_at' => 'datetime',
        ];
    }

    private static function soloDigitos(?string $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor);

        // «Mi empresa» acepta texto libre en el RNC; si no parece un RNC (9) o una cédula (11), se
        // deja vacío para que el usuario lo escriba bien en vez de precargar un valor que no cabe.
        return in_array(strlen((string) $digitos), [9, 11], true) ? $digitos : null;
    }
}
