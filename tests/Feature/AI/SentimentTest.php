<?php

declare(strict_types=1);

use App\Modules\AI\Enums\Sentiment;
use App\Modules\AI\Models\AiSentimentAnalysis;
use App\Modules\AI\Services\SentimentService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\WhatsApp\Models\WaMessage;
use App\Modules\WhatsApp\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Sentiment Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->sentiment = app(SentimentService::class);
});

it('clasifica un texto positivo', function (): void {
    expect($this->sentiment->analyze('Gracias, excelente servicio, me encanta')['sentiment'])
        ->toBe('positive');
});

it('clasifica un texto negativo', function (): void {
    expect($this->sentiment->analyze('Esto es pésimo y terrible, pongo una queja')['sentiment'])
        ->toBe('negative');
});

it('analiza automáticamente los mensajes entrantes de WhatsApp', function (): void {
    app(WhatsAppService::class)->recordInbound('18090000000', 'Excelente, muchas gracias, genial');

    $analysis = AiSentimentAnalysis::first();

    expect($analysis)->not->toBeNull()
        ->and($analysis->sentiment)->toBe(Sentiment::Positive)
        ->and($analysis->analyzable_type)->toBe(WaMessage::class)
        ->and($analysis->company_id)->toBe($this->company->id);
});
