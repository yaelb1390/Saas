<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Modules\SocialCommerce\Enums\TemplateChannel;
use App\Modules\SocialCommerce\Models\Rule;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * El modo prueba por HTTP: nunca debe llamar a Zernio ni crear ningún registro — es una
 * simulación, no una automatización de verdad.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tienda'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@tienda.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->product = Product::create(['sku' => 'CAM-1', 'name' => 'Camisa Nike Air', 'cost' => '800', 'price' => '1500']);
});

it('simula una coincidencia sin crear ningún registro ni llamar a Zernio', function (): void {
    $rule = Rule::factory()->create(['product_id' => $this->product->id, 'status' => RuleStatus::Active, 'keywords' => ['precio']]);
    $rule->templates()->create(['channel' => TemplateChannel::Dm->value, 'body' => 'La {producto} cuesta {precio}.', 'position' => 0]);

    $respuesta = $this->actingAs($this->owner)
        ->postJson(route('panel.social-commerce.sandbox.simulate'), ['comentario' => '¿Cuánto es el precio?'])
        ->assertOk()
        ->json();

    expect($respuesta['coincide'])->toBeTrue()
        ->and($respuesta['regla'])->toBe($rule->name)
        ->and(collect($respuesta['pasos'])->pluck('texto')->implode(' | '))->toContain('Camisa Nike Air');

    // Nada de esto tocó la base más allá de lo que ya existía.
    expect(\App\Modules\SocialCommerce\Models\Conversation::count())->toBe(0)
        ->and(\App\Modules\SocialCommerce\Models\RuleTemplateUsage::count())->toBe(0);
});

it('dice claramente cuando ninguna regla coincide', function (): void {
    Rule::factory()->create(['product_id' => $this->product->id, 'status' => RuleStatus::Active, 'keywords' => ['precio']]);

    $respuesta = $this->actingAs($this->owner)
        ->postJson(route('panel.social-commerce.sandbox.simulate'), ['comentario' => 'qué lindo todo'])
        ->assertOk()
        ->json();

    expect($respuesta['coincide'])->toBeFalse();
});

it('una regla pausada no cuenta para el sandbox', function (): void {
    Rule::factory()->create(['product_id' => $this->product->id, 'status' => RuleStatus::Paused, 'keywords' => ['precio']]);

    $respuesta = $this->actingAs($this->owner)
        ->postJson(route('panel.social-commerce.sandbox.simulate'), ['comentario' => 'precio'])
        ->assertOk()
        ->json();

    expect($respuesta['coincide'])->toBeFalse();
});

it('un empleado sin permiso de gestión no puede usar el sandbox', function (): void {
    $staff = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Empleada',
        'email' => 'empleada@tienda.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($staff)->get(route('panel.social-commerce.sandbox'))->assertForbidden();
});
