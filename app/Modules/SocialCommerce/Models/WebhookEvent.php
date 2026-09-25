<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Models;

use App\Modules\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un aviso recibido del webhook propio de Social Commerce. Mismo diseño que `PolarWebhookEvent`
 * a propósito (ver la migración): vive en la plataforma, no lleva `BelongsToCompany` porque al
 * recibir el aviso puede no haberse resuelto la empresa todavía.
 *
 * @property array<string, mixed> $payload
 */
final class WebhookEvent extends Model
{
    public const RESULT_APPLIED = 'applied';

    public const RESULT_IGNORED = 'ignored';

    public const RESULT_UNRESOLVED = 'unresolved';

    protected $table = 'social_commerce_webhook_events';

    protected $fillable = ['event_id', 'type', 'result', 'company_id', 'note', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function resolveAs(string $result, ?string $note = null, ?int $companyId = null): self
    {
        $this->update([
            'result' => $result,
            'note' => $note,
            'company_id' => $companyId ?? $this->company_id,
        ]);

        return $this;
    }
}
