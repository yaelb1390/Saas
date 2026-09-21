<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\TenantAttribution;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/*
 * ¿A qué empresa se le atribuye lo que pasa?
 *
 * La trampa, que ya mordió a esta pantalla: `CurrentCompany` NO es nulo para el operador de la
 * plataforma. El middleware le fija la empresa de su sesión o, si no eligió, LA PRIMERA POR ID. Así que
 * un error, un correo de prueba o un intento de acceso suyo —que no son de ninguna empresa— quedaban como
 * de la empresa 1, sin dar error y sin que nadie lo notara mientras hubiera una sola empresa de prueba.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();
    SystemEvent::olvidarSiHayTabla();

    $this->primera = app(CompanyService::class)->create(new CreateCompanyData(name: 'Primera'));
    $this->segunda = app(CompanyService::class)->create(new CreateCompanyData(name: 'Segunda'));

    $this->operador = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'op@atribucion.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $this->dueno = User::create([
        'company_id' => $this->segunda->id, 'name' => 'Dueno', 'email' => 'dueno@atribucion.test',
        'password' => 'secret-password',
    ]);
});

it('un usuario de empresa es de SU empresa, aunque la activa sea otra', function (): void {
    app(CurrentCompany::class)->set($this->primera->id);
    $this->actingAs($this->dueno);

    expect(TenantAttribution::companyId())->toBe($this->segunda->id);
});

it('el operador de la plataforma no es de ninguna empresa, aunque tenga una activa', function (): void {
    // Es lo que hace el middleware: la primera por id.
    app(CurrentCompany::class)->set($this->primera->id);
    $this->actingAs($this->operador);

    expect(TenantAttribution::companyId())->toBeNull();
});

it('el operador es de la empresa que la ruta nombra, cuando de verdad actúa sobre una', function (): void {
    Route::middleware('web')->get('/__atribucion/{company}', fn (string $company) => response()->json([
        'empresa' => TenantAttribution::companyId(),
    ]));

    $this->actingAs($this->operador)
        ->getJson('/__atribucion/'.$this->segunda->id)
        ->assertOk()
        ->assertJson(['empresa' => $this->segunda->id]);
});

it('en una ruta sin empresa, el operador sigue sin serlo de ninguna', function (): void {
    Route::middleware('web')->get('/__atribucion-sin-empresa', fn () => response()->json([
        'empresa' => TenantAttribution::companyId(),
    ]));

    $this->actingAs($this->operador)
        ->getJson('/__atribucion-sin-empresa')
        ->assertOk()
        ->assertJson(['empresa' => null]);
});

it('sin nadie con sesión, es la empresa activa: un job, un webhook, el portal del cliente', function (): void {
    app(CurrentCompany::class)->set($this->segunda->id);

    expect(TenantAttribution::companyId())->toBe($this->segunda->id);
});

it('sin sesión ni empresa activa no es de nadie', function (): void {
    expect(TenantAttribution::companyId())->toBeNull();
});

it('un suceso del operador ya no se le anota a la primera empresa', function (): void {
    // Es el correo de prueba de la plataforma, el borrado de una empresa, un cambio de módulos…
    app(CurrentCompany::class)->set($this->primera->id);
    $this->actingAs($this->operador);

    SystemEvent::registrar('mail.test_sent', 'Se envió un correo de prueba');

    expect(SystemEvent::query()->where('type', 'mail.test_sent')->sole()->company_id)->toBeNull();
});

it('un suceso de un usuario de empresa se anota a su empresa', function (): void {
    $this->actingAs($this->dueno);

    SystemEvent::registrar('auth.login', 'Inició sesión');

    expect(SystemEvent::query()->where('type', 'auth.login')->sole()->company_id)->toBe($this->segunda->id);
});

it('la empresa que se pasa a mano gana sobre la deducida', function (): void {
    $this->actingAs($this->operador);

    SystemEvent::registrar('platform.company_suspended', 'Empresa suspendida', companyId: $this->primera->id);

    expect(SystemEvent::query()->where('type', 'platform.company_suspended')->sole()->company_id)->toBe($this->primera->id);
});
