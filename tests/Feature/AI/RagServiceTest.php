<?php

declare(strict_types=1);

use App\Modules\AI\Models\AiDocumentChunk;
use App\Modules\AI\Services\RagService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'AI Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->rag = app(RagService::class);
});

it('indexa un documento en chunks con embeddings', function (): void {
    $document = $this->rag->index('Doc', 'uno dos tres cuatro cinco seis');

    expect($document->chunks()->count())->toBeGreaterThan(0)
        ->and($document->chunks()->first()->embedding)->toBeArray();
});

it('recupera el chunk más relevante para la consulta', function (): void {
    $this->rag->index('Envíos', 'Realizamos envíos a todo el país en 24 horas mediante mensajería.');
    $this->rag->index('Garantía', 'La garantía cubre defectos de fábrica por un año.');

    $results = $this->rag->retrieve('cuánto tardan los envíos');

    expect($results)->not->toBeEmpty()
        ->and($results->first()->content)->toContain('envíos');
});

it('answer devuelve una respuesta y sus fuentes', function (): void {
    $this->rag->index('Pagos', 'Aceptamos efectivo y tarjeta de crédito.');

    $result = $this->rag->answer('formas de pago');

    expect($result['answer'])->toBeString()->not->toBeEmpty()
        ->and($result['sources'])->not->toBeEmpty();
});

it('aísla los chunks por empresa', function (): void {
    $this->rag->index('Doc', 'hola mundo empresa uno');

    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra AI'));
    app(CurrentCompany::class)->set($other->id);

    expect(AiDocumentChunk::count())->toBe(0);
});
