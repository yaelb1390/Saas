<?php

declare(strict_types=1);

namespace App\Modules\AI\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fragmento de un documento con su embedding (JSON de floats).
 *
 * @property float|null $similarity puntuación transitoria asignada durante la recuperación
 */
class AiDocumentChunk extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'ai_document_id',
        'position',
        'content',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'embedding' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(AiDocument::class, 'ai_document_id');
    }
}
