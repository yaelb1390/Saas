<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Modules\SocialCommerce\Models\Rule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
 * La pantalla de reglas por HTTP: permisos, aislamiento por empresa y que crear/editar de verdad
 * sincroniza con Zernio (con Http::fake, nunca contra el servicio real).
 */

uses(RefreshDatabase::class);

function fakeZernioAutomations(string $automationId = 'auto_1'): void
{
    Http::fake([
        'api.zernio.com/v1/profiles' => Http::response(['profiles' => [['_id' => 'perfil_1', 'isDefault' => true]]], 200),
        'api.zernio.com/v1/comment-automations/*' => Http::response([], 200),
        'api.zernio.com/v1/comment-automations' => Http::response(['automation' => ['id' => $automationId, 'isActive' => true]], 200),
    ]);
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tienda'));
    app(CurrentCompany::class)->set($this->company->id);
    $this->company->forceFill(['social_api_key' => str_repeat('a', 64)])->save();

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@tienda.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->staff = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Empleada',
        'email' => 'empleada@tienda.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->product = Product::create(['sku' => 'CAM-1', 'name' => 'Camisa Nike Air', 'cost' => '800', 'price' => '1500']);
});

/**
 * @return array<string, mixed>
 */
function datosDeRegla(int $productId, array $overrides = []): array
{
    return array_merge([
        'name' => 'Precio camisa',
        'zernio_account_id' => 'acc_1',
        'trigger' => 'comment',
        'product_id' => $productId,
        'keywords' => ['precio', 'cuanto'],
        'match_mode' => 'word',
        'dm_templates' => ['La {producto} cuesta {precio}.'],
    ], $overrides);
}

// ---------------------------------------------------------------- Permisos

it('un invitado no puede ver la lista de reglas', function (): void {
    $this->get(route('panel.social-commerce.index'))->assertRedirect(route('login'));
});

it('un empleado sin el permiso no puede crear una regla', function (): void {
    $this->actingAs($this->staff)
        ->post(route('panel.social-commerce.store'), datosDeRegla($this->product->id))
        ->assertForbidden();

    expect(Rule::count())->toBe(0);
});

// ---------------------------------------------------------------- Alta

it('el dueño crea una regla, se guardan sus plantillas y se sincroniza con Zernio', function (): void {
    fakeZernioAutomations();

    $this->actingAs($this->owner)
        ->post(route('panel.social-commerce.store'), datosDeRegla($this->product->id))
        ->assertRedirect(route('panel.social-commerce.index'));

    $rule = Rule::first();
    expect($rule->name)->toBe('Precio camisa')
        ->and($rule->product_id)->toBe($this->product->id)
        ->and($rule->keywords)->toBe(['precio', 'cuanto'])
        ->and($rule->dmTemplates()->count())->toBe(1)
        ->and($rule->zernio_automation_id)->toBe('auto_1')
        ->and($rule->status)->toBe(RuleStatus::Active);
});

it('el producto tiene que ser de la empresa activa', function (): void {
    app(CurrentCompany::class)->forget();
    $otraEmpresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra tienda'));
    app(CurrentCompany::class)->set($otraEmpresa->id);
    $productoAjeno = Product::create(['sku' => 'X-1', 'name' => 'De otra empresa', 'cost' => '1', 'price' => '2']);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->owner)
        ->post(route('panel.social-commerce.store'), datosDeRegla($productoAjeno->id))
        ->assertSessionHasErrors('product_id');

    expect(Rule::count())->toBe(0);
});

it('rechaza una plantilla con una variable que no existe', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.social-commerce.store'), datosDeRegla($this->product->id, [
            'dm_templates' => ['La {producto} cuesta {precio}, tallas: {tallas}'],
        ]))
        ->assertSessionHasErrors('dm_templates');

    expect(Rule::count())->toBe(0);
});

// ---------------------------------------------------------------- Aislamiento por empresa

it('una empresa no puede editar ni borrar la regla de otra', function (): void {
    fakeZernioAutomations();
    $this->actingAs($this->owner)->post(route('panel.social-commerce.store'), datosDeRegla($this->product->id));
    $rule = Rule::firstOrFail();

    app(CurrentCompany::class)->forget();
    $otraEmpresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra tienda'));
    app(CurrentCompany::class)->set($otraEmpresa->id);
    $otroOwner = withRole(User::create([
        'company_id' => $otraEmpresa->id, 'name' => 'Otro dueño',
        'email' => 'otro@otra.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($otroOwner)->get(route('panel.social-commerce.edit', $rule))->assertNotFound();
    $this->actingAs($otroOwner)->delete(route('panel.social-commerce.destroy', $rule))->assertNotFound();

    expect(Rule::withoutGlobalScopes()->find($rule->id))->not->toBeNull(); // sigue existiendo, no se borró
});

// ---------------------------------------------------------------- Estado y borrado

it('pausar y encender cambian el estado y llaman a Zernio', function (): void {
    fakeZernioAutomations();
    $this->actingAs($this->owner)->post(route('panel.social-commerce.store'), datosDeRegla($this->product->id));
    $rule = Rule::firstOrFail();

    $this->actingAs($this->owner)
        ->post(route('panel.social-commerce.toggle', $rule), ['is_active' => '0'])
        ->assertRedirect();

    expect($rule->fresh()->status)->toBe(RuleStatus::Paused);

    $this->actingAs($this->owner)
        ->post(route('panel.social-commerce.toggle', $rule), ['is_active' => '1'])
        ->assertRedirect();

    expect($rule->fresh()->status)->toBe(RuleStatus::Active);
});

it('borrar una regla la quita también de Zernio y de la base', function (): void {
    fakeZernioAutomations();
    $this->actingAs($this->owner)->post(route('panel.social-commerce.store'), datosDeRegla($this->product->id));
    $rule = Rule::firstOrFail();

    $this->actingAs($this->owner)->delete(route('panel.social-commerce.destroy', $rule))->assertRedirect();

    // Rule usa SoftDeletes a propósito: la fila sigue en la base (con deleted_at), lo que
    // desaparece es de las consultas normales, que es lo que de verdad le importa al panel.
    expect(Rule::find($rule->id))->toBeNull()
        ->and(Rule::withoutGlobalScopes()->find($rule->id))->not->toBeNull();
});
