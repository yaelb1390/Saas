<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un e-CF emitido. Su estado solo cambia a través de `ElectronicInvoiceService` (que valida la
 * transición y la audita), nunca con un `update(['status' => …])` suelto.
 *
 * @property Environment $environment
 * @property EcfType $ecf_type
 * @property string $e_ncf
 * @property EcfStatus $status
 * @property string $provider
 * @property string|null $track_id
 * @property string|null $security_code
 * @property bool $sends_summary
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon $issue_date
 */
final class ElectronicInvoice extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public function files(): HasMany
    {
        return $this->hasMany(ElectronicInvoiceFile::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ElectronicInvoiceResponse::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ElectronicInvoiceAuditLog::class);
    }

    public function file(string $kind): ?ElectronicInvoiceFile
    {
        return $this->files()->where('kind', $kind)->latest('id')->first();
    }

    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'ecf_type' => EcfType::class,
            'status' => EcfStatus::class,
            'issue_date' => 'date',
            'total' => 'decimal:2',
            'itbis_total' => 'decimal:2',
            'sends_summary' => 'boolean',
            'signed_at' => 'datetime',
            'schema_date' => 'date',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
