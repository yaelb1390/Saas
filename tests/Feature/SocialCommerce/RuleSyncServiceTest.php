<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Modules\SocialCommerce\Enums\TemplateChannel;
use App\Modules\SocialCommerce\Models\Rule;
use App\Modules\SocialCommerce\Services\RuleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();

    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tienda'));
    app(CurrentCompany::class)->set($this->company->id);
    // El formato «sk_...» solo lo exige el formulario del panel; ZernioClient únicamente
    // comprueba que la clave no esté vacía.
    $this->company->update(['social_api_key' => str_repeat('a', 64)]);

    $this->product = Product::create(['sku' => 'CAM-1', 'name' => 'Camisa Nike Air', 'cost' => '800', 'price' => '1500']);

    $this->rule = Rule::factory()->create([
        'product_id' => $this->product->id,
        'zernio_account_id' => 'acc_123',
    ]);
    $this->rule->templates()->create(['channel' => TemplateChannel::Dm->value, 'body' => 'La {producto} cuesta {precio}.', 'position' => 0]);
});

it('crea la automatización en Zernio activa desde el principio y guarda el identificador', function (): void {
    Http::fake([
        // createAutomation() resuelve el perfil por omisión antes de crear (ZernioClient::defaultProfileId()).
        'api.zernio.com/v1/profiles' => Http::response(['profiles' => [['_id' => 'perfil_1', 'isDefault' => true]]], 200),
        'api.zernio.com/v1/comment-automations' => Http::response(['automation' => ['id' => 'auto_1', 'isActive' => true]], 200),
    ]);

    $rule = app(RuleSyncService::class)->sync($this->rule);

    expect($rule->zernio_automation_id)->toBe('auto_1')
        ->and($rule->status)->toBe(RuleStatus::Active)
        ->and($rule->sync_error)->toBeNull()
        ->and($rule->last_synced_at)->not->toBeNull();

    // La regla nace draft; si el payload se armara con ese estado, Zernio recibiría
    // isActive:false y ClientZernio forzaría un segundo PATCH para apagarla (ver el comentario en
    // RuleSyncService::sync()). Esta prueba habría fallado con el bug original.
    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.zernio.com/v1/comment-automations'
        && $request['isActive'] === true
        && $request['keywords'] === ['precio', 'cuanto', 'vale']
        && str_contains($request['dmMessage'], 'Camisa Nike Air')
        && str_contains($request['dmMessage'], 'DOP 1,500.00'));
});

it('marca la regla en error si Zernio rechaza la automatización, sin lanzar', function (): void {
    Http::fake([
        'api.zernio.com/v1/profiles' => Http::response(['profiles' => [['_id' => 'perfil_1', 'isDefault' => true]]], 200),
        'api.zernio.com/v1/comment-automations' => Http::response(['message' => 'cuenta inválida'], 422),
    ]);

    $rule = app(RuleSyncService::class)->sync($this->rule);

    expect($rule->status)->toBe(RuleStatus::Error)
        ->and($rule->sync_error)->toContain('cuenta inválida')
        ->and($rule->zernio_automation_id)->toBeNull();
});

it('pausar apaga la automatización en Zernio sin borrar la regla', function (): void {
    // zernio_automation_id no está en $fillable a propósito (lo gestiona RuleSyncService, no un
    // formulario) — por eso se fija por asignación directa y no con update().
    $this->rule->zernio_automation_id = 'auto_1';
    $this->rule->status = RuleStatus::Active;
    $this->rule->save();

    Http::fake(['api.zernio.com/v1/comment-automations/auto_1' => Http::response([], 200)]);

    $rule = app(RuleSyncService::class)->pause($this->rule);

    expect($rule->status)->toBe(RuleStatus::Paused);
    Http::assertSent(fn ($request): bool => $request['isActive'] === false);
});

it('borrar una regla sincronizada la borra también en Zernio', function (): void {
    $this->rule->zernio_automation_id = 'auto_1';
    $this->rule->save();

    Http::fake(['api.zernio.com/v1/comment-automations/auto_1' => Http::response([], 200)]);

    app(RuleSyncService::class)->delete($this->rule);

    expect(Rule::find($this->rule->id))->toBeNull();
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE');
});
