<?php

declare(strict_types=1);

namespace App\Modules\Printing\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Printing\Enums\ConnectionType;
use App\Modules\Printing\Enums\PrinterStatus;
use App\Modules\Printing\Support\PaperSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Una impresora registrada por la empresa: la térmica de la caja, la de etiquetas del almacén, la A4
 * de la oficina. `SoftDeletes` porque el historial (`print_jobs`) sigue apuntando a ella después de
 * retirarla: un trabajo impreso hace un año no debe perder de vista en qué impresora salió.
 *
 * Auditable porque es configuración de hardware compartida por todo el equipo: quién la registró y
 * quién le cambió el tamaño de papel importa cuando algo sale mal en la caja.
 */
final class Printer extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'created_by',
        'name',
        'manufacturer',
        'model',
        'connection_type',
        'bt_device_id',
        'bt_service_uuid',
        'bt_characteristic_uuid',
        'address',
        'paper_size',
        'custom_width_mm',
        'custom_height_mm',
        'settings',
        'last_status',
        'last_seen_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'connection_type' => ConnectionType::class,
            'last_status' => PrinterStatus::class,
            'settings' => 'array',
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
            'custom_width_mm' => 'integer',
            'custom_height_mm' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function esBluetooth(): bool
    {
        return $this->connection_type === ConnectionType::Bluetooth;
    }

    public function esDelNavegador(): bool
    {
        return $this->connection_type === ConnectionType::Browser;
    }

    /** El ancho de papel en milímetros, resolviendo el caso «personalizado». */
    public function anchoMm(): int
    {
        if ($this->paper_size === 'custom') {
            return (int) ($this->custom_width_mm ?? 0);
        }

        return PaperSize::widthMm((string) $this->paper_size);
    }

    /** El alto de papel en milímetros, o null si es un rollo continuo (térmico). */
    public function altoMm(): ?int
    {
        if ($this->paper_size === 'custom') {
            return $this->custom_height_mm;
        }

        return PaperSize::heightMm((string) $this->paper_size);
    }
}
