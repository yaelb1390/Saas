<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Modules\SocialCommerce\Models\ContactIdentity;
use App\Modules\SocialCommerce\Models\Conversation;
use App\Modules\SocialCommerce\Models\Message;
use App\Modules\SocialCommerce\Models\OpportunityLink;
use App\Modules\SocialCommerce\Models\Rule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tienda'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@tienda.test', 'password' => 'secret-password',
    ]), 'owner');
});

it('cuenta reglas, conversaciones, mensajes y clientes enlazados', function (): void {
    $product = Product::create(['sku' => 'CAM-1', 'name' => 'Camisa', 'cost' => '1', 'price' => '2']);
    Rule::factory()->create(['product_id' => $product->id, 'status' => RuleStatus::Active]);
    Rule::factory()->create(['product_id' => $product->id, 'status' => RuleStatus::Paused]);

    $identidad = ContactIdentity::create([
        'channel' => 'instagram', 'external_id' => 'igsid_1', 'first_seen_at' => Carbon::now(), 'last_seen_at' => Carbon::now(),
        'customer_id' => Customer::create(['name' => 'Ana'])->id,
    ]);

    $conversation = Conversation::create([
        'contact_identity_id' => $identidad->id, 'zernio_conversation_id' => 'conv_1',
        'zernio_account_id' => 'acc_1', 'platform' => 'instagram', 'last_message_at' => Carbon::now(),
    ]);

    Message::create(['conversation_id' => $conversation->id, 'direction' => 'incoming', 'body' => 'precio?', 'sent_at' => Carbon::now()]);
    Message::create(['conversation_id' => $conversation->id, 'direction' => 'outgoing', 'body' => 'cuesta X', 'sent_at' => Carbon::now()]);

    $respuesta = $this->actingAs($this->owner)->get(route('panel.social-commerce.dashboard'))->assertOk();

    $respuesta->assertSee('1', false); // al menos una cifra en pantalla
    $respuesta->assertViewHas('reglasActivas', 1);
    $respuesta->assertViewHas('conversaciones', 1);
    $respuesta->assertViewHas('mensajesEntrantes', 1); // solo cuenta el entrante, no el saliente
    $respuesta->assertViewHas('clientesEnlazados', 1);
});

it('no rompe cuando no hay nada todavía', function (): void {
    $this->actingAs($this->owner)->get(route('panel.social-commerce.dashboard'))->assertOk()
        ->assertSee('Todavía no has creado ninguna regla');
});

it('nunca menciona "Instagram → WhatsApp": ese clic no se puede medir', function (): void {
    $this->actingAs($this->owner)->get(route('panel.social-commerce.dashboard'))
        ->assertDontSee('Instagram → WhatsApp')
        ->assertDontSee('Instagram - WhatsApp');
});

it('suma el valor de las oportunidades creadas desde aquí', function (): void {
    $product = Product::create(['sku' => 'CAM-1', 'name' => 'Camisa', 'cost' => '1', 'price' => '2']);
    $rule = Rule::factory()->create(['product_id' => $product->id, 'status' => RuleStatus::Active]);
    $identidad = ContactIdentity::create([
        'channel' => 'instagram', 'external_id' => 'igsid_1', 'first_seen_at' => Carbon::now(), 'last_seen_at' => Carbon::now(),
        'customer_id' => Customer::create(['name' => 'Ana'])->id,
    ]);
    $conversation = Conversation::create([
        'contact_identity_id' => $identidad->id, 'rule_id' => $rule->id, 'zernio_conversation_id' => 'conv_1',
        'zernio_account_id' => 'acc_1', 'platform' => 'instagram', 'last_message_at' => Carbon::now(),
    ]);

    app(\App\Modules\CRM\Services\CrmService::class);
    $pipeline = \App\Modules\CRM\Models\Pipeline::where('is_default', true)->first();
    $opportunity = app(\App\Modules\CRM\Services\CrmService::class)->openOpportunity($pipeline, 'Camisa', '2500', $identidad->customer);
    OpportunityLink::create(['opportunity_id' => $opportunity->id, 'conversation_id' => $conversation->id, 'rule_id' => $rule->id]);

    $this->actingAs($this->owner)->get(route('panel.social-commerce.dashboard'))
        ->assertOk()
        ->assertViewHas('valorOportunidades', 2500.0);
});
