<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\SocialCommerce\Models\Conversation;
use App\Modules\SocialCommerce\Models\ContactIdentity;
use App\Modules\SocialCommerce\Models\OpportunityLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/*
 * El puente entre una conversación de Instagram y el CRM: enlazar con un cliente (o crear uno) y
 * abrir una oportunidad. Lo que importa aquí es que no se pueda crear una oportunidad sin cliente,
 * que no se duplique si ya existe una, y el aislamiento por empresa de siempre.
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

    $this->identidad = ContactIdentity::create([
        'company_id' => $this->company->id, 'channel' => 'instagram', 'external_id' => 'igsid_1',
        'external_username' => 'ana.ig', 'display_name' => 'Ana', 'first_seen_at' => Carbon::now(), 'last_seen_at' => Carbon::now(),
    ]);

    $this->conversation = Conversation::create([
        'contact_identity_id' => $this->identidad->id, 'zernio_conversation_id' => 'conv_1',
        'zernio_account_id' => 'acc_1', 'platform' => 'instagram', 'last_message_at' => Carbon::now(),
    ]);
});

it('enlaza la conversación con un cliente nuevo escrito a mano', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.social-commerce.conversations.link-customer', $this->conversation), ['nombre' => 'Ana Pérez'])
        ->assertRedirect();

    expect($this->identidad->fresh()->customer?->name)->toBe('Ana Pérez')
        ->and(Customer::count())->toBe(1);
});

it('enlaza la conversación con un cliente ya existente', function (): void {
    $cliente = Customer::create(['name' => 'Cliente viejo']);

    $this->actingAs($this->owner)
        ->post(route('panel.social-commerce.conversations.link-customer', $this->conversation), ['customer_id' => $cliente->id])
        ->assertRedirect();

    expect($this->identidad->fresh()->customer_id)->toBe($cliente->id)
        ->and(Customer::count())->toBe(1); // no se creó uno nuevo
});

it('no crea una oportunidad sin cliente enlazado primero', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.social-commerce.conversations.opportunity', $this->conversation))
        ->assertRedirect();

    expect(OpportunityLink::count())->toBe(0);
});

it('crea la oportunidad enlazada a la conversación una vez hay cliente', function (): void {
    $cliente = Customer::create(['name' => 'Ana Pérez']);
    $this->identidad->update(['customer_id' => $cliente->id]);

    $this->actingAs($this->owner)
        ->post(route('panel.social-commerce.conversations.opportunity', $this->conversation))
        ->assertRedirect(route('panel.social-commerce.conversations.show', $this->conversation));

    $link = OpportunityLink::first();
    expect($link)->not->toBeNull()
        ->and($link->conversation_id)->toBe($this->conversation->id)
        ->and($link->opportunity->customer_id)->toBe($cliente->id);
});

it('no permite abrir una segunda oportunidad para la misma conversación', function (): void {
    $cliente = Customer::create(['name' => 'Ana Pérez']);
    $this->identidad->update(['customer_id' => $cliente->id]);

    $this->actingAs($this->owner)->post(route('panel.social-commerce.conversations.opportunity', $this->conversation));
    $this->actingAs($this->owner)->post(route('panel.social-commerce.conversations.opportunity', $this->conversation));

    expect(OpportunityLink::count())->toBe(1);
});

it('una empresa no ve ni puede tocar la conversación de otra', function (): void {
    app(CurrentCompany::class)->forget();
    $otraEmpresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra tienda'));
    app(CurrentCompany::class)->set($otraEmpresa->id);
    $otroOwner = withRole(User::create([
        'company_id' => $otraEmpresa->id, 'name' => 'Otro dueño',
        'email' => 'otro@otra.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($otroOwner)->get(route('panel.social-commerce.conversations.show', $this->conversation))->assertNotFound();
    $this->actingAs($otroOwner)
        ->post(route('panel.social-commerce.conversations.link-customer', $this->conversation), ['nombre' => 'Intruso'])
        ->assertNotFound();

    expect($this->identidad->fresh()->customer_id)->toBeNull();
});
