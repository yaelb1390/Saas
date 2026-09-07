<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Loans\Models\Loan;
use Database\Factories\CRM\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Cliente del CRM. Aislado por company_id.
 */
class Customer extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    /**
     * Laravel busca la factory en `Database\Factories\Modules\CRM\Models\` —cambia `App\` por
     * `Database\Factories\` y conserva el resto—, que obligaría a un árbol de carpetas espejo del de
     * los módulos. Se dice a mano y la factory vive donde se la encuentra.
     */
    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone',
        'tax_id',
        'cedula',
        'address',
        // Donde vive, en coordenadas. Lo aprende el sistema del primer reparto: la direccion
        // escrita casi nunca basta, y el punto exacto si.
        'latitude',
        'longitude',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // Cadena y no float, igual que en la entrega: siete decimales son ~1 cm, y en coma
            // flotante dos lecturas idénticas pueden no comparar iguales.
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * @return HasMany<Opportunity, $this>
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    /**
     * @return HasMany<Loan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * @return HasMany<CustomerDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class)->latest('id');
    }
}
