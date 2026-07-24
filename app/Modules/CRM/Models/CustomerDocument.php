<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento adjunto al perfil de un cliente (foto de cédula, contrato, etc.). El contenido vive en
 * la base como base64: persiste en serverless sin disco externo. Uso previsto: archivos pequeños.
 *
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property string $content
 */
class CustomerDocument extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'customer_id',
        'name',
        'mime',
        'size',
        'content',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Tamaño legible para la interfaz (KB/MB). */
    public function humanSize(): string
    {
        if ($this->size >= 1048576) {
            return number_format($this->size / 1048576, 1).' MB';
        }

        return max(1, (int) round($this->size / 1024)).' KB';
    }

    /** ¿Es una imagen (para poder previsualizarla) o un PDF/otro? */
    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
