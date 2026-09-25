<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Ajustes de Social Commerce de una empresa: número de WhatsApp de destino y credenciales del
 * webhook propio. La cuenta de Zernio NO vive aquí: se reutiliza `companies.social_api_key`.
 *
 * @property bool $is_active
 * @property string|null $whatsapp_number
 * @property string $webhook_token
 * @property string $webhook_secret
 * @property string|null $zernio_webhook_id
 */
final class Settings extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;

    protected $table = 'social_commerce_settings';

    protected $fillable = ['company_id', 'is_active', 'whatsapp_number', 'zernio_webhook_id'];

    /**
     * La fila de una empresa, creándola apagada si no existe.
     *
     * El token y el secreto se generan aquí una sola vez, mismo motivo que
     * `SocialWelcomeSetting::paraEmpresa()`: regenerarlos al guardar dejaría el webhook ya dado de
     * alta en Zernio apuntando a una dirección que deja de reconocer las firmas.
     */
    public static function paraEmpresa(int $companyId): self
    {
        $ajustes = self::withoutGlobalScopes()->where('company_id', $companyId)->first();

        if ($ajustes !== null) {
            return $ajustes;
        }

        $ajustes = new self;

        // forceFill: el token y el secreto no están en $fillable, no pueden llegar de un formulario.
        $ajustes->forceFill([
            'company_id' => $companyId,
            'is_active' => false,
            'webhook_token' => Str::random(48),
            'webhook_secret' => Str::random(64),
        ])->save();

        return $ajustes;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // Quien tenga el secreto puede fabricar avisos falsos en nombre de la cuenta del
            // cliente. Cifrado en reposo, mismo criterio que `social_api_key` y el secreto de
            // bienvenida de Social.
            'webhook_secret' => 'encrypted',
        ];
    }
}
