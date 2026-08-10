<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un aviso recibido de Polar. Ver la migración para el porqué de la tabla.
 *
 * Vive en la plataforma (no pertenece a ninguna empresa), así que no lleva ámbito de tenant: al
 * recibir el aviso todavía no se sabe de quién es.
 *
 * @property array<string, mixed> $payload
 */
class PolarWebhookEvent extends Model
{
    /** El aviso se aplicó: se activó, renovó o revocó una suscripción. */
    public const RESULT_APPLIED = 'applied';

    /** Evento conocido pero sin efecto para nosotros (o sin nada que cambiar). */
    public const RESULT_IGNORED = 'ignored';

    /** No se pudo saber a qué empresa o plan corresponde. Requiere intervención manual. */
    public const RESULT_UNRESOLVED = 'unresolved';

    protected $fillable = [
        'event_id',
        'type',
        'result',
        'company_id',
        'note',
        'payload',
    ];

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

    /**
     * Deja constancia de cómo acabó el aviso.
     */
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
