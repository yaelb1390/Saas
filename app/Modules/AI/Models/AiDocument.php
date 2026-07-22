<?php

declare(strict_types=1);

namespace App\Modules\AI\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Documento de la base de conocimiento para RAG.
 */
class AiDocument extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'title',
        'source',
        'content',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<AiDocumentChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(AiDocumentChunk::class);
    }
}
