<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Certificado digital de firma de una empresa. Solo datos públicos y la contraseña cifrada: la clave
 * privada nunca sale del archivo .p12 cifrado y nunca se enseña.
 *
 * @property string $path
 * @property string $password
 * @property string $subject
 * @property string $issuer
 * @property string $serial
 * @property string $fingerprint
 * @property Carbon $valid_from
 * @property Carbon $valid_to
 * @property bool $is_active
 */
final class ElectronicCertificate extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'path', 'subject', 'issuer', 'serial', 'fingerprint', 'valid_from', 'valid_to',
        'is_active', 'replaced_at', 'uploaded_by',
    ];

    // La contraseña nunca aparece al serializar ni en la auditoría.
    protected $hidden = ['password', 'path'];

    /** @var array<int, string> */
    protected $auditExclude = ['password', 'path'];

    /** vigente | por_vencer | vencido | aun_no_valido */
    public function status(): string
    {
        $avisarDias = (int) config('ecf.certificate_warning_days', 30);

        return match (true) {
            $this->valid_from->isFuture() => 'aun_no_valido',
            $this->valid_to->isPast() => 'vencido',
            $this->valid_to->lte(now()->addDays($avisarDias)) => 'por_vencer',
            default => 'vigente',
        };
    }

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'is_active' => 'boolean',
            'replaced_at' => 'datetime',
        ];
    }
}
