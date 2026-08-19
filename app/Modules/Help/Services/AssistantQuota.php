<?php

declare(strict_types=1);

namespace App\Modules\Help\Services;

use App\Modules\AI\Models\AiSetting;
use App\Modules\Help\Models\AssistantQuestion;

/**
 * El tope diario de preguntas por empresa.
 *
 * Existe porque cada pregunta se paga en el proveedor de IA y la paga el operador de la plataforma,
 * no la empresa que pregunta. Sin techo, un bucle accidental o un cliente curioso aparecen en la
 * factura del mes sin que nadie se haya enterado.
 *
 * Se cuenta desde `assistant_questions` y no desde un contador en caché: en Vercel la caché ES la
 * base de datos, así que no se ahorra ninguna consulta, y una tabla real se puede mirar y explicar
 * cuando un cliente pregunte por qué se le acabó.
 *
 * OJO: se cuentan TODAS las preguntas, también las que no llamaron al proveedor. Es deliberado. Lo
 * que se está limitando no es solo el gasto, sino el uso; y contar solo las caras invitaría a
 * descubrir que preguntando cosas que no están en el manual no gastas cuota.
 */
final class AssistantQuota
{
    /**
     * ¿Cuántas le quedan hoy a esta empresa?
     *
     * El día es el del servidor, que está en la zona del cliente (America/Santo_Domingo). Un tope
     * diario que se reinicia a las ocho de la noche sería incomprensible para quien lo sufre.
     */
    public function restantes(int $companyId): int
    {
        $usadas = AssistantQuestion::query()
            ->where('company_id', $companyId)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        return max(0, $this->tope() - $usadas);
    }

    public function agotada(int $companyId): bool
    {
        return $this->restantes($companyId) === 0;
    }

    /** El techo configurado en Administración › IA de la plataforma. */
    public function tope(): int
    {
        return max(0, AiSetting::actual()->daily_limit ?? 0);
    }
}
