<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las empresas EN PRUEBA también se ponen al día con su plan.
 *
 * La migración anterior (`..._grant_new_modules_to_pro_and_sync_companies`) solo sincronizó las
 * suscripciones en estado `active`, y esa fue una equivocación: una suscripción en prueba da acceso
 * exactamente igual —`Subscription::isUsable()` acepta `trialing` mientras no se le pase la fecha— y
 * en producción TODAS las empresas de clientes estaban en prueba. Resultado: se ampliaron los planes
 * y a las empresas no les llegó ni un módulo.
 *
 * Ese es justo el síntoma que se reportó: «cuando lo asigno no aparece en las empresas». El plan se
 * cambia, la lista propia de la empresa sigue mandando, y nadie avisa.
 *
 * `past_due` queda fuera a propósito: puede seguir viéndose por gracia, pero ampliarle el acceso a
 * quien no ha pagado no es lo que nadie quiere de una migración.
 *
 * Como siempre: SOLO SE CONCEDE, NUNCA SE RETIRA.
 */
return new class extends Migration
{
    public function up(): void
    {
        $suscripciones = DB::table('subscriptions')
            ->whereIn('status', ['active', 'trialing'])
            ->get(['company_id', 'plan_id']);

        foreach ($suscripciones as $suscripcion) {
            $plan = DB::table('plans')->where('id', $suscripcion->plan_id)->first(['modules']);
            $empresa = DB::table('companies')->where('id', $suscripcion->company_id)->first(['id', 'modules']);

            if ($plan === null || $empresa === null || $empresa->modules === null) {
                // Ya lo tiene todo: nada que conceder.
                continue;
            }

            if ($plan->modules === null) {
                // El plan da todo, también lo que venga: la empresa pasa a lo mismo.
                DB::table('companies')->where('id', $empresa->id)->update(['modules' => null]);

                continue;
            }

            $union = array_values(array_unique(array_merge(
                $this->lista($empresa->modules),
                $this->lista($plan->modules),
            )));

            DB::table('companies')->where('id', $empresa->id)->update(['modules' => json_encode($union)]);
        }
    }

    /**
     * No se deshace, por lo mismo que la anterior: no sabemos qué tenía cada empresa antes, y
     * quitarle un módulo que ya está usando le rompe la pantalla sin que nadie cambiara su contrato.
     */
    public function down(): void {}

    /** @return array<int, string> */
    private function lista(?string $json): array
    {
        $lista = json_decode((string) $json, true);

        return is_array($lista) ? array_values(array_filter($lista, 'is_string')) : [];
    }
};
