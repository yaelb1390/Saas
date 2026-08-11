<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/*
 * El catálogo de módulos tiene que estar completo y cuadrar con lo que exige el sistema.
 *
 * Son tres listas que deben decir lo mismo: los módulos del registro, los que las rutas exigen con
 * el middleware `module:` y los que el formulario de planes ofrece marcar.
 *
 * Si se descuadran, el fallo es SILENCIOSO y de los caros: una ruta que exige un módulo inexistente
 * queda bloqueada para siempre —ningún plan puede incluir algo que no está en el catálogo— y nadie
 * ve un error, solo una pantalla que «no funciona».
 */

uses(RefreshDatabase::class);

it('toda ruta exige módulos que existen en el catálogo', function (): void {
    $exigidos = collect(Route::getRoutes())
        ->flatMap(fn ($ruta) => $ruta->gatherMiddleware())
        ->filter(fn ($m): bool => is_string($m) && str_starts_with($m, 'module:'))
        // El middleware admite varios separados por coma («module:pos,quick_pos» = cualquiera).
        ->flatMap(fn (string $m): array => explode(',', substr($m, 7)))
        ->unique()
        ->values();

    $desconocidos = $exigidos->diff(ModuleRegistry::keys())->values()->all();

    expect($desconocidos)->toBe([], 'Rutas que exigen módulos fuera del catálogo: '.implode(', ', $desconocidos));
});

it('el formulario de planes ofrece TODOS los módulos del catálogo', function (): void {
    // Un módulo que no aparece aquí no se puede incluir en ningún plan: existiría en el código y
    // sería invendible.
    app(CurrentCompany::class)->forget();
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Plataforma'));

    $super = User::create([
        'company_id' => $company->id, 'name' => 'Operador', 'email' => 'super@plataforma.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $respuesta = $this->actingAs($super)->get(route('platform.plans'))->assertOk();

    foreach (ModuleRegistry::all() as $clave => $etiqueta) {
        // Con escapado (por defecto): «IA & RAG» viaja como «IA &amp; RAG» en el HTML.
        $respuesta->assertSee(is_array($etiqueta) ? ($etiqueta['label'] ?? $clave) : $etiqueta);
        $respuesta->assertSee('value="'.$clave.'"', false);
    }
});

it('marcar todos los módulos guarda «todos», no una lista congelada', function (): void {
    // Es la diferencia entre un plan que hereda los módulos futuros y uno que se queda anclado.
    // Un plan con lista explícita necesita una migración cada vez que nace un módulo, y si se
    // olvida, sus clientes se quedan sin él sin que nadie lo note.
    app(CurrentCompany::class)->forget();
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Plataforma'));

    $super = User::create([
        'company_id' => $company->id, 'name' => 'Operador', 'email' => 'super@plataforma.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $this->actingAs($super)->post(route('platform.plans.store'), [
        'name' => 'Todo incluido', 'slug' => 'todo', 'price' => '3000',
        'billing_cycle' => 'monthly', 'trial_days' => 0,
        'modules' => ModuleRegistry::keys(),
    ])->assertSessionHasNoErrors();

    $plan = Plan::firstWhere('slug', 'todo');

    expect($plan->modules)->toBeNull()
        ->and($plan->includesModule('ai'))->toBeTrue();
});

it('dejar uno sin marcar guarda la lista explícita', function (): void {
    // La otra cara: un plan recortado sí debe guardar exactamente lo que se marcó.
    app(CurrentCompany::class)->forget();
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Plataforma'));

    $super = User::create([
        'company_id' => $company->id, 'name' => 'Operador', 'email' => 'super@plataforma.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $sinIa = array_values(array_diff(ModuleRegistry::keys(), ['ai']));

    $this->actingAs($super)->post(route('platform.plans.store'), [
        'name' => 'Casi todo', 'slug' => 'casi', 'price' => '2000',
        'billing_cycle' => 'monthly', 'trial_days' => 0, 'modules' => $sinIa,
    ])->assertSessionHasNoErrors();

    $plan = Plan::firstWhere('slug', 'casi');

    expect($plan->modules)->not->toBeNull()
        ->and($plan->includesModule('ai'))->toBeFalse();
});
