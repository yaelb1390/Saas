<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Services\CompanyOnboardingService;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Application\Diagnostics;
use App\Modules\ElectronicInvoicing\Domain\EmissionMode;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Facturación (NCF) y Facturación Electrónica (e-CF), de serie en todas las empresas.
 *
 * Nació de una prueba real: la empresa tenía la electrónica configurada y en «En paralelo», pero no
 * el módulo; el Super Admin veía las pantallas (el filtro de módulos lo deja pasar) y ninguna venta
 * generaba su e-CF, sin un solo aviso.
 */

uses(RefreshDatabase::class);

it('la electronica arrastra la facturacion al guardar una lista de modulos', function (): void {
    expect(ModuleRegistry::sanitize(['pos', 'e_invoicing']))->toBe(['pos', 'e_invoicing', 'billing'])
        // Sin la electrónica, nada se añade: Facturación sola sigue siendo válida.
        ->and(ModuleRegistry::sanitize(['pos', 'billing']))->toBe(['pos', 'billing'])
        ->and(ModuleRegistry::sanitize(['pos', 'inventado']))->toBe(['pos']);
});

it('una empresa dada de alta con modulos elegidos lleva siempre los dos de serie', function (): void {
    app(CurrentCompany::class)->forget();

    $empresa = app(CompanyOnboardingService::class)->register(
        new CreateCompanyData(name: 'Heladería Norte'),
        ['name' => 'Dueña', 'email' => 'duena@norte.test', 'password' => 'secret-password'],
        ['pos', 'quick_pos'],
    );

    expect($empresa->modules)->toBe(['pos', 'quick_pos', 'billing', 'e_invoicing']);
});

it('la migracion concede los dos a planes y empresas con lista, sin tocar lo demas', function (): void {
    $plan = (int) DB::table('plans')->insertGetId([
        'name' => 'Básico', 'slug' => 'basico-'.Str::random(5), 'price' => 1000, 'billing_cycle' => 'monthly',
        'modules' => json_encode(['pos', 'billing']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $planCompleto = (int) DB::table('plans')->insertGetId([
        'name' => 'Todo', 'slug' => 'todo-'.Str::random(5), 'price' => 3000, 'billing_cycle' => 'monthly',
        'modules' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $empresa = (int) DB::table('companies')->insertGetId([
        'name' => 'Cafetería', 'slug' => 'cafeteria-'.Str::random(5),
        'modules' => json_encode(['quick_pos', 'inventory']), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $migracion = require database_path('migrations/2026_10_06_100000_enable_billing_and_e_invoicing_by_default.php');
    $migracion->up();
    // Idempotente: la segunda pasada no duplica nada.
    $migracion->up();

    $lista = fn (string $tabla, int $id): ?array => ($j = DB::table($tabla)->where('id', $id)->value('modules')) === null
        ? null : json_decode((string) $j, true);

    expect($lista('plans', $plan))->toBe(['pos', 'billing', 'e_invoicing'])
        ->and($lista('plans', $planCompleto))->toBeNull()
        ->and($lista('companies', $empresa))->toBe(['quick_pos', 'inventory', 'billing', 'e_invoicing']);
});

it('un plan nuevo trae marcadas la facturacion y la electronica', function (): void {
    app(CurrentCompany::class)->forget();
    $plataforma = app(CompanyService::class)->create(new CreateCompanyData(name: 'Plataforma'));
    $admin = User::create([
        'company_id' => $plataforma->id, 'name' => 'Admin', 'email' => 'admin@plataforma.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    // Sin planes creados, el único formulario de módulos de la página es el de alta.
    $alta = $this->actingAs($admin)->get(route('platform.plans'))->assertOk()->getContent();
    expect($alta)->toContain('value="billing" checked')
        ->and($alta)->toContain('value="e_invoicing" checked')
        ->and($alta)->not->toContain('value="pos" checked');
});

it('el diagnostico avisa si la empresa no tiene los modulos aunque quien mira entre igual', function (): void {
    app(CurrentCompany::class)->forget();
    $empresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'Sin módulo'));
    $empresa->forceFill(['modules' => ['pos', 'billing']])->save();
    app(CurrentCompany::class)->set($empresa->id);

    ElectronicInvoicingSettings::paraEmpresa($empresa)->forceFill(['emission_mode' => EmissionMode::Sombra->value])->save();

    $item = collect(app(Diagnostics::class)->checks(Company::query()->findOrFail($empresa->id), false))->firstWhere('key', 'modulos');

    expect($item['level'])->toBe('error')
        ->and($item['detail'])->toBe('La empresa no tiene Facturación Electrónica: ninguna venta generará su e-CF.');
});
