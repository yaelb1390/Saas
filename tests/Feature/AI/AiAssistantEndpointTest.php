<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\AI\Models\AiDocument;
use App\Modules\AI\Services\RagService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'AI Panel Co'));
    $this->user = withRole(User::create([
        'company_id' => $this->company->id,
        'name' => 'Admin',
        'email' => 'admin@ai.test',
        'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);
});

it('indexa un documento desde el panel', function (): void {
    $this->actingAs($this->user)
        ->post(route('panel.ai.documents.store'), [
            '_form' => 'ai_document',
            'title' => 'Devoluciones',
            'content' => 'Aceptamos devoluciones dentro de los 30 días con el recibo.',
        ])
        ->assertRedirect();

    $doc = AiDocument::first();
    expect($doc)->not->toBeNull()
        ->and($doc->title)->toBe('Devoluciones')
        ->and($doc->chunks()->count())->toBeGreaterThan(0);
});

it('responde una pregunta y flashea respuesta y fuentes', function (): void {
    app(RagService::class)
        ->index('Envíos', 'Los envíos tardan 24 horas a todo el país mediante mensajería.');

    $this->actingAs($this->user)
        ->post(route('panel.ai.ask'), ['query' => 'cuánto tardan los envíos'])
        ->assertRedirect()
        ->assertSessionHas('ai_answer')
        ->assertSessionHas('ai_sources');
});

it('valida que la pregunta no esté vacía', function (): void {
    $this->actingAs($this->user)
        ->post(route('panel.ai.ask'), ['query' => ''])
        ->assertSessionHasErrors('query');
});

it('elimina un documento indexado', function (): void {
    $doc = app(RagService::class)->index('Temporal', 'contenido de prueba para indexar');

    $this->actingAs($this->user)
        ->delete(route('panel.ai.documents.destroy', $doc))
        ->assertRedirect();

    expect(AiDocument::whereKey($doc->id)->exists())->toBeFalse()
        ->and($doc->chunks()->count())->toBe(0);
});

it('no permite eliminar un documento de otra empresa', function (): void {
    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra AI Co'));
    app(CurrentCompany::class)->set($other->id);
    $foreign = app(RagService::class)->index('Ajeno', 'documento de otra empresa aislado');

    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->user)
        ->delete(route('panel.ai.documents.destroy', $foreign->id))
        ->assertNotFound();

    expect(AiDocument::withoutGlobalScopes()->whereKey($foreign->id)->exists())->toBeTrue();
});
