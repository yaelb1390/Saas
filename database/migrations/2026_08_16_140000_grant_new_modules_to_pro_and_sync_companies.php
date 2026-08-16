<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los planes se quedaron atrás: Redes sociales y Entregas se construyeron después de crearlos.
 *
 * El plan intermedio enumeraba once módulos y no conocía los cuatro que llegaron luego, así que
 * quien lo paga no ve pantallas que su escalón sí debería incluir. El básico NO los recibe a
 * propósito: si todos los planes incluyen lo mismo, no hay motivo para subir de escalón.
 *
 * Y hace falta la segunda mitad: la lista de una empresa es la que manda de verdad —el middleware
 * mira esa, no la del plan—, y las de las empresas ya dadas de alta son más cortas que la de su
 * plan. Sin ponerlas al día, ampliar el plan no cambiaría nada de lo que ve el cliente.
 *
 * REGLA: solo se concede, nunca se retira. Quitarle a una empresa un módulo que ya está usando le
 * rompería la pantalla sin que nadie haya cambiado su contrato.
 *
 * `modules = NULL` significa «todos, también los futuros», así que esas filas ya están al día por
 * definición y no se tocan.
 */
return new class extends Migration
{
    /** Lo que se construyó después de crear el plan intermedio. */
    private const NUEVOS = ['social', 'delivery', 'hr', 'ai'];

    public function up(): void
    {
        $this->concederAlPlanIntermedio();
        $this->ponerEmpresasAlDia();
    }

    /**
     * Marcha atrás solo del plan.
     *
     * Lo concedido a las empresas NO se deshace, y no es un olvido: no sabemos qué tenía cada una
     * antes, y adivinarlo mal dejaría a un cliente sin una pantalla que ya está usando. Deshacer
     * esta migración devuelve el catálogo del plan, no la historia de cada empresa.
     */
    public function down(): void
    {
        foreach ($this->planesConListaPropia() as $plan) {
            $modulos = $this->lista($plan->modules);

            if (! $this->esElIntermedio($modulos)) {
                continue;
            }

            DB::table('plans')->where('id', $plan->id)->update([
                'modules' => json_encode(array_values(array_diff($modulos, self::NUEVOS))),
            ]);
        }
    }

    private function concederAlPlanIntermedio(): void
    {
        foreach ($this->planesConListaPropia() as $plan) {
            $modulos = $this->lista($plan->modules);

            if (! $this->esElIntermedio($modulos)) {
                continue;
            }

            DB::table('plans')->where('id', $plan->id)->update([
                'modules' => json_encode(array_values(array_unique(array_merge($modulos, self::NUEVOS)))),
            ]);
        }
    }

    /**
     * Cada empresa recibe lo que su plan activo incluya, sin perder nada de lo que ya tenía.
     *
     * Puede que una empresa tenga módulos que su plan no da —se conceden a mano desde el panel— y
     * esos se conservan: la unión, no la sustitución.
     */
    private function ponerEmpresasAlDia(): void
    {
        $suscripciones = DB::table('subscriptions')
            ->where('status', 'active')
            ->get(['company_id', 'plan_id']);

        foreach ($suscripciones as $suscripcion) {
            $plan = DB::table('plans')->where('id', $suscripcion->plan_id)->first(['modules']);
            $empresa = DB::table('companies')->where('id', $suscripcion->company_id)->first(['id', 'modules']);

            if ($plan === null || $empresa === null || $empresa->modules === null) {
                // La empresa ya lo tiene todo: nada que conceder.
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
     * ¿Es el escalón intermedio?
     *
     * Se reconoce por lo que YA incluye y no por su nombre: el nombre lo escribe el dueño y puede
     * cambiarlo, mientras que «tiene CRM» es lo que de verdad separa aquí el plan intermedio del
     * básico. Buscar «Pro» habría fallado en silencio en cuanto alguien lo renombrara.
     *
     * @param  array<int, string>  $modulos
     */
    private function esElIntermedio(array $modulos): bool
    {
        return in_array('crm', $modulos, true);
    }

    /** @return array<int, object> */
    private function planesConListaPropia(): array
    {
        return DB::table('plans')->whereNotNull('modules')->get(['id', 'modules'])->all();
    }

    /** @return array<int, string> */
    private function lista(?string $json): array
    {
        $lista = json_decode((string) $json, true);

        return is_array($lista) ? array_values(array_filter($lista, 'is_string')) : [];
    }
};
