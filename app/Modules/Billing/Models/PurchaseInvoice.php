<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Enums\GoodsServicesType;
use App\Modules\Billing\Support\TaxIdKind;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Comprobante de compra recibido de un proveedor (para el 606 de la DGII). El adjunto (foto/PDF) vive
 * en la base como base64. Los montos y fechas siguen el formato 606.
 *
 * @property string $ncf
 * @property GoodsServicesType $goods_services_type
 * @property TaxIdKind $provider_tax_id_kind
 * @property Carbon $invoice_date
 * @property Carbon|null $payment_date
 * @property string $mime
 */
class PurchaseInvoice extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'provider_tax_id',
        'provider_tax_id_kind',
        'provider_name',
        'ncf',
        'ncf_modified',
        'goods_services_type',
        'invoice_date',
        'payment_date',
        'amount',
        'itbis',
        'itbis_retenido',
        'isr_retenido',
        'isc',
        'other_taxes',
        'tip',
        'payment_method',
        'file_name',
        'file_mime',
        'file_size',
        'file_content',
        'extraction_status',
        'raw_extraction',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'goods_services_type' => GoodsServicesType::class,
            'provider_tax_id_kind' => TaxIdKind::class,
            'invoice_date' => 'date',
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'itbis' => 'decimal:2',
            'itbis_retenido' => 'decimal:2',
            'isr_retenido' => 'decimal:2',
            'isc' => 'decimal:2',
            'other_taxes' => 'decimal:2',
            'tip' => 'decimal:2',
            'file_size' => 'integer',
            'raw_extraction' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasFile(): bool
    {
        return $this->file_content !== null && $this->file_content !== '';
    }

    /** ¿El adjunto es una imagen (para previsualizar) o un PDF/otro? */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->file_mime, 'image/');
    }

    /** Tamaño legible del adjunto (KB/MB). */
    public function humanSize(): string
    {
        if ($this->file_size >= 1048576) {
            return number_format($this->file_size / 1048576, 1).' MB';
        }

        return max(1, (int) round($this->file_size / 1024)).' KB';
    }
}
