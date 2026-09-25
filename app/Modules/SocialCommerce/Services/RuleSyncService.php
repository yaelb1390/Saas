<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Services;

use App\Modules\Social\Exceptions\SocialException;
use App\Modules\Social\Services\ZernioClient;
use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Modules\SocialCommerce\Models\Rule;
use App\Modules\SocialCommerce\Support\ZernioAutomationPayload;

/**
 * Pone una regla al día en Zernio: la crea o la actualiza, y guarda el resultado.
 *
 * Reutiliza `App\Modules\Social\Services\ZernioClient` tal cual —es la única forma de hablar con
 * Zernio que existe en el proyecto—, sin tocar ni una línea de ese archivo (arquitectura, sección
 * 1 y 2).
 *
 * Nunca lanza: un fallo de Zernio dentro de esto se guarda en `sync_error`, no revienta la
 * pantalla que guardó la regla. Quien llama a este servicio no necesita un `try/catch` propio.
 */
final class RuleSyncService
{
    public function __construct(private readonly ZernioAutomationPayload $payloadBuilder) {}

    /**
     * Crea o actualiza la automatización de Zernio para esta regla, según ya tenga o no un
     * `zernio_automation_id`.
     */
    public function sync(Rule $rule): Rule
    {
        /*
         * El estado se decide ANTES de construir el payload, no después.
         *
         * `ZernioAutomationPayload::build()` lee `$rule->status` para mandar `isActive`. Si se
         * dejara en `draft`, se enviaría `isActive: false` — y `ZernioClient::createAutomation()`
         * respeta esa intención con un segundo PATCH que apaga la automatización recién creada
         * (documentado ahí: Zernio ignora `isActive:false` al crear, así que el cliente lo fuerza
         * a mano). Una regla nueva o que se reintenta debe nacer/volver ACTIVA, así que el estado
         * optimista se fija primero; si algo falla más abajo, se corrige a `error` en el catch.
         *
         * `null` cuenta como «recién creada», no solo `Draft`: `StoreRuleRequest::paraRegla()` no
         * manda `status` a propósito (es un campo gestionado por este servicio, no por el
         * formulario), así que un `Rule::create()` recién hecho tiene el atributo SIN FIJAR en
         * memoria —el valor por omisión de la columna solo existe en la base, Eloquent no lo relee
         * solo— hasta que algo lo recarga. Comprobar solo `=== RuleStatus::Draft` dejaba la regla
         * atascada en null para siempre: el `if` nunca entraba, `isActive` salía `false` y el
         * estado nunca se guardaba como activo. Lo delató esta misma prueba contra el controlador,
         * no la del servicio a solas —esa usaba una factory que sí fija `status` a mano.
         */
        if ($rule->status === null || $rule->status === RuleStatus::Draft || $rule->status === RuleStatus::Error) {
            $rule->status = RuleStatus::Active;
        }

        $cliente = new ZernioClient($rule->company);
        $cuerpo = $this->payloadBuilder->build($rule);

        try {
            if ($rule->estaSincronizada()) {
                $cliente->updateAutomation((string) $rule->zernio_automation_id, $cuerpo);
            } else {
                $creada = $cliente->createAutomation($cuerpo);
                $rule->zernio_automation_id = (string) ($creada['id'] ?? '');
            }

            $rule->last_synced_at = now();
            $rule->sync_error = null;
        } catch (SocialException $e) {
            $rule->sync_error = $e->getMessage();
            $rule->status = RuleStatus::Error;
        }

        $rule->save();

        return $rule;
    }

    /** Apaga la regla en Zernio sin borrarla: sigue configurada, solo deja de contestar. */
    public function pause(Rule $rule): Rule
    {
        if ($rule->estaSincronizada()) {
            try {
                (new ZernioClient($rule->company))->updateAutomation((string) $rule->zernio_automation_id, ['isActive' => false]);
            } catch (SocialException $e) {
                $rule->sync_error = $e->getMessage();
                $rule->save();

                return $rule;
            }
        }

        $rule->update(['status' => RuleStatus::Paused, 'sync_error' => null]);

        return $rule;
    }

    /** Vuelve a encenderla en Zernio. Si nunca se sincronizó, la crea. */
    public function activate(Rule $rule): Rule
    {
        if (! $rule->estaSincronizada()) {
            return $this->sync($rule);
        }

        try {
            (new ZernioClient($rule->company))->updateAutomation((string) $rule->zernio_automation_id, ['isActive' => true]);
        } catch (SocialException $e) {
            $rule->update(['status' => RuleStatus::Error, 'sync_error' => $e->getMessage()]);

            return $rule;
        }

        $rule->update(['status' => RuleStatus::Active, 'sync_error' => null]);

        return $rule;
    }

    /**
     * Borra la automatización de Zernio antes de borrar la regla localmente.
     *
     * No lanza si Zernio no responde: quedarse con una automatización huérfana en Zernio es
     * molesto, pero no poder borrar la regla del panel porque el proveedor está caído es peor.
     */
    public function delete(Rule $rule): void
    {
        if ($rule->estaSincronizada()) {
            try {
                (new ZernioClient($rule->company))->deleteAutomation((string) $rule->zernio_automation_id);
            } catch (SocialException) {
                // Se ignora a propósito: ver el comentario del método.
            }
        }

        $rule->delete();
    }
}
