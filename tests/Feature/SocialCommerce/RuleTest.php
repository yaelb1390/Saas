<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Modules\SocialCommerce\Enums\TemplateChannel;
use App\Modules\SocialCommerce\Models\Rule;
use App\Modules\SocialCommerce\Models\RuleTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tienda A'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->product = Product::create(['sku' => 'CAM-1', 'name' => 'Camisa Nike Air', 'cost' => '800', 'price' => '1500']);
});

it('crea una regla con su producto y hereda la empresa activa sin que nadie la mande', function (): void {
    $rule = Rule::factory()->create(['product_id' => $this->product->id]);

    expect($rule->company_id)->toBe($this->company->id)
        ->and($rule->status)->toBe(RuleStatus::Draft)
        ->and($rule->keywords)->toBe(['precio', 'cuanto', 'vale'])
        ->and($rule->product->name)->toBe('Camisa Nike Air');
});

it('ordena las plantillas por canal y posición, principal primero', function (): void {
    $rule = Rule::factory()->create(['product_id' => $this->product->id]);

    RuleTemplate::create(['rule_id' => $rule->id, 'channel' => TemplateChannel::Dm->value, 'body' => 'Alternativa', 'position' => 1]);
    RuleTemplate::create(['rule_id' => $rule->id, 'channel' => TemplateChannel::Dm->value, 'body' => 'Principal', 'position' => 0]);
    RuleTemplate::create(['rule_id' => $rule->id, 'channel' => TemplateChannel::Public->value, 'body' => 'Pública', 'position' => 0]);

    expect($rule->dmTemplates()->pluck('body')->all())->toBe(['Principal', 'Alternativa'])
        ->and($rule->publicTemplates()->pluck('body')->all())->toBe(['Pública']);
});

it('aisla las reglas por empresa: una empresa nunca ve las reglas de otra', function (): void {
    Rule::factory()->create(['product_id' => $this->product->id, 'name' => 'Regla de Tienda A']);

    app(CurrentCompany::class)->forget();
    $companyB = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tienda B'));
    app(CurrentCompany::class)->set($companyB->id);

    $productB = Product::create(['sku' => 'ZAP-1', 'name' => 'Zapatos', 'cost' => '10', 'price' => '20']);
    Rule::factory()->create(['product_id' => $productB->id, 'name' => 'Regla de Tienda B']);

    expect(Rule::all())->toHaveCount(1)
        ->and(Rule::first()->name)->toBe('Regla de Tienda B');

    app(CurrentCompany::class)->set($this->company->id);
    expect(Rule::all())->toHaveCount(1)
        ->and(Rule::first()->name)->toBe('Regla de Tienda A');
});

it('no permite borrar en firme un producto que tiene una regla apuntándole', function (): void {
    // Product usa SoftDeletes: un delete() normal solo pone deleted_at y no dispara la
    // restricción de la llave foránea. La regla que de verdad importa —no perder el producto que
    // una automatización sigue usando para contestar el precio— se prueba con forceDelete().
    Rule::factory()->create(['product_id' => $this->product->id]);

    expect(fn () => $this->product->forceDelete())->toThrow(\Illuminate\Database\QueryException::class);
});
