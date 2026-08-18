<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Poner los planes y las empresas al día con los módulos que se construyeron después.
 *
 * La regla que todo esto protege es una: SOLO SE CONCEDE, NUNCA SE RETIRA. Quitarle a una empresa un
 * módulo que ya está usando le rompería la pantalla sin que nadie haya cambiado su contrato, y eso es
 * peor que no ampliar nada.
 */

uses(RefreshDatabase::class);

/** Ejecuta la migración a mano, sobre los datos que monte cada test. */
function ponerAlDia(): void
{
    $migracion = require database_path('migrations/2026_08_16_140000_grant_new_modules_to_pro_and_sync_companies.php');
    $migracion->up();
}

function crearPlan(string $nombre, ?array $modulos): int
{
    return (int) DB::table('plans')->insertGetId([
        'name' => $nombre,
        'slug' => Str::slug($nombre).'-'.Str::random(5),
        'price' => 1000,
        'billing_cycle' => 'monthly',
        'modules' => $modulos === null ? null : json_encode($modulos),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function crearEmpresa(string $nombre, ?array $modulos): int
{
    return (int) DB::table('companies')->insertGetId([
        'name' => $nombre,
        'slug' => Str::slug($nombre).'-'.Str::random(5),
        'modules' => $modulos === null ? null : json_encode($modulos),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function suscribir(int $empresa, int $plan, string $estado = 'active'): void
{
    DB::table('subscriptions')->insert([
        'company_id' => $empresa,
        'plan_id' => $plan,
        'status' => $estado,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function modulosDelPlan(int $id): ?array
{
    $json = DB::table('plans')->where('id', $id)->value('modules');

    return $json === null ? null : json_decode((string) $json, true);
}

function modulosDeLaEmpresa(int $id): ?array
{
    $json = DB::table('companies')->where('id', $id)->value('modules');

    return $json === null ? null : json_decode((string) $json, true);
}

// ---------------------------------------------------------------------- Los planes

it('el plan intermedio recibe los módulos que llegaron después', function (): void {
    $pro = crearPlan('Pro', ['pos', 'inventory', 'crm', 'whatsapp', 'finance']);

    ponerAlDia();

    expect(modulosDelPlan($pro))->toContain('social')
        ->and(modulosDelPlan($pro))->toContain('delivery')
        ->and(modulosDelPlan($pro))->toContain('hr')
        ->and(modulosDelPlan($pro))->toContain('ai')
        // Y no pierde lo que ya tenía.
        ->and(modulosDelPlan($pro))->toContain('pos');
});

it('el plan básico NO los recibe', function (): void {
    // Si todos los planes incluyen lo mismo, no hay motivo para subir de escalón.
    $basico = crearPlan('Básico', ['pos', 'quick_pos', 'inventory', 'sales', 'reports']);

    ponerAlDia();

    expect(modulosDelPlan($basico))->not->toContain('social')
        ->and(modulosDelPlan($basico))->not->toContain('delivery')
        ->and(modulosDelPlan($basico))->toHaveCount(5);
});

it('se reconoce el plan por lo que incluye, no por su nombre', function (): void {
    /*
     * El nombre lo escribe el dueño y puede cambiarlo. Buscar «Pro» habría fallado en silencio en
     * cuanto alguien lo renombrara, y el cliente se habría quedado sin sus módulos sin que nadie
     * viera un error.
     */
    $renombrado = crearPlan('Comercio Plus', ['pos', 'crm', 'finance']);

    ponerAlDia();

    expect(modulosDelPlan($renombrado))->toContain('social');
});

it('el plan que ya lo da todo no se toca', function (): void {
    // `modules = NULL` significa «todos, también los futuros»: ya está al día por definición.
    $empresarial = crearPlan('Empresarial', null);

    ponerAlDia();

    expect(modulosDelPlan($empresarial))->toBeNull();
});

it('pasarla dos veces no duplica nada', function (): void {
    $pro = crearPlan('Pro', ['pos', 'crm']);

    ponerAlDia();
    ponerAlDia();

    $modulos = modulosDelPlan($pro);

    expect($modulos)->toBe(array_values(array_unique($modulos)));
});

// ---------------------------------------------------------------------- Las empresas

it('la empresa se pone al día con lo que su plan incluye', function (): void {
    /*
     * La lista de la empresa es la que manda: el middleware mira esa, no la del plan. Sin este paso,
     * ampliar el plan no cambiaría NADA de lo que el cliente ve en pantalla.
     */
    $pro = crearPlan('Pro', ['pos', 'crm', 'finance']);
    $empresa = crearEmpresa('Colmado', ['pos']);
    suscribir($empresa, $pro);

    ponerAlDia();

    expect(modulosDeLaEmpresa($empresa))->toContain('crm')
        ->and(modulosDeLaEmpresa($empresa))->toContain('social');
});

it('no le quita a la empresa lo que su plan no da', function (): void {
    // Puede tener módulos concedidos a mano desde el panel. Es la unión, no la sustitución.
    $basico = crearPlan('Básico', ['pos']);
    $empresa = crearEmpresa('Colmado', ['pos', 'delivery']);
    suscribir($empresa, $basico);

    ponerAlDia();

    expect(modulosDeLaEmpresa($empresa))->toContain('delivery');
});

it('una empresa sin suscripción activa no se toca', function (): void {
    $pro = crearPlan('Pro', ['pos', 'crm']);
    $empresa = crearEmpresa('De baja', ['pos']);
    suscribir($empresa, $pro, 'cancelled');

    ponerAlDia();

    expect(modulosDeLaEmpresa($empresa))->toBe(['pos']);
});

it('en el plan que lo da todo, la empresa también pasa a tenerlo todo', function (): void {
    // Si no, seguiría sin recibir los módulos futuros que su plan sí promete.
    $empresarial = crearPlan('Empresarial', null);
    $empresa = crearEmpresa('Grande', ['pos']);
    suscribir($empresa, $empresarial);

    ponerAlDia();

    expect(modulosDeLaEmpresa($empresa))->toBeNull();
});

// ------------------------------------------------------- Las empresas en prueba también cuentan

/** La segunda pasada: la primera se dejó fuera las suscripciones en prueba. */
function ponerAlDiaLasDePrueba(): void
{
    $migracion = require database_path('migrations/2026_08_16_160000_sync_trialing_companies_with_their_plan.php');
    $migracion->up();
}

it('una empresa EN PRUEBA también recibe los módulos de su plan', function (): void {
    /*
     * Es el fallo que esto corrige. Una suscripción en prueba da acceso igual que una activa
     * —`isUsable()` acepta `trialing`—, y en producción todas las empresas de clientes estaban en
     * prueba: se ampliaron los planes y a nadie le llegó nada.
     */
    $pro = crearPlan('Pro', ['pos', 'crm', 'delivery', 'hr']);
    $empresa = crearEmpresa('Motores', ['pos', 'crm']);
    suscribir($empresa, $pro, 'trialing');

    ponerAlDiaLasDePrueba();

    expect(modulosDeLaEmpresa($empresa))->toContain('delivery')
        ->and(modulosDeLaEmpresa($empresa))->toContain('hr');
});

it('una suspendida no recibe nada', function (): void {
    // Ampliarle el acceso a quien no ha pagado no es lo que nadie quiere de una migración.
    $pro = crearPlan('Pro', ['pos', 'crm', 'delivery']);
    $empresa = crearEmpresa('Morosa', ['pos']);
    suscribir($empresa, $pro, 'suspended');

    ponerAlDiaLasDePrueba();

    expect(modulosDeLaEmpresa($empresa))->toBe(['pos']);
});

it('tampoco una que venció sin pagar', function (): void {
    $pro = crearPlan('Pro', ['pos', 'crm', 'delivery']);
    $empresa = crearEmpresa('Vencida', ['pos']);
    suscribir($empresa, $pro, 'past_due');

    ponerAlDiaLasDePrueba();

    expect(modulosDeLaEmpresa($empresa))->toBe(['pos']);
});
