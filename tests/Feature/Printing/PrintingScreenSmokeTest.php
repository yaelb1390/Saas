<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * PRIMER SMOKE TEST: que la pantalla entera compile y responda antes de escribir nada más fino.
 * Con cinco pestañas Blade y un editor con vista previa en vivo, el riesgo real no es la lógica de
 * negocio —esa la prueban los tests de servicio— sino una llave `@endif` de más o una variable que
 * la vista espera y el controlador no manda.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Impresión Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->admin = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@printing.test', 'password' => 'secret-password',
    ]), 'owner');
});

it('la pantalla del Centro de Impresión responde, vacía (onboarding)', function (): void {
    $this->withoutVite();

    $html = $this->actingAs($this->admin)
        ->get(route('panel.printing.index'))
        ->assertOk()->getContent();

    expect($html)->toContain('Configura tu primera impresora')
        ->and($html)->toContain('Buscar impresoras')
        ->and($html)->toContain('Bluetooth');
});
