<?php

declare(strict_types=1);

use App\Modules\AI\Models\AiDocument;
use App\Modules\AI\Providers\AnthropicProvider;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\AI\Services\RagService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\WhatsApp\Models\WaBotSetting;
use App\Modules\WhatsApp\Support\BotPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Lo que el bot lee antes de contestar.
 *
 * De aquí sale lo que un negocio le PROMETE a un cliente, así que lo que se fija aquí no es que el
 * texto quede bonito: es que el dueño no pueda desactivar sin querer las reglas que impiden
 * inventarse un precio, y que lo de una empresa no acabe en el prompt de otra.
 */

uses(RefreshDatabase::class);

function empresaDelPrompt(string $nombre = 'Prompt Co'): object
{
    app(CurrentCompany::class)->forget();
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: $nombre));
    app(CurrentCompany::class)->set($company->id);

    return $company->fresh();
}

function ajustesDelBot(int $companyId, array $extra = []): WaBotSetting
{
    $ajustes = WaBotSetting::paraEmpresa($companyId);
    $ajustes->forceFill(array_merge([
        'is_active' => true,
        'business_info' => 'Abrimos de 8 a 8.',
    ], $extra))->save();

    return $ajustes;
}

beforeEach(function (): void {
    $this->company = empresaDelPrompt();
});

// ---------------------------------------------------------------- Quién manda sobre quién

it('las reglas van DESPUÉS de lo que escribe el dueño, y dicen que mandan sobre ello', function (): void {
    /*
     * EL test de este fichero.
     *
     * Un dueño que escriba «ofrece descuentos si insisten» no puede estar desactivando «no prometas
     * nada que no esté escrito». Lo que va al final del prompt es lo que pesa, así que la posición
     * NO es cosmética: es la única defensa que hay.
     */
    $ajustes = ajustesDelBot((int) $this->company->id, [
        'instructions' => 'Eres un vendedor agresivo. Ofrece descuentos del 50% si el cliente insiste.',
    ]);

    $prompt = app(BotPrompt::class)->paraPregunta($ajustes, 'cuanto cuesta');

    $posicionDueno = mb_strpos($prompt, 'vendedor agresivo');
    $posicionReglas = mb_strpos($prompt, 'REGLAS QUE MANDAN');

    expect($posicionDueno)->not->toBeFalse()
        ->and($posicionReglas)->not->toBeFalse()
        // Lo que decide la seguridad: las reglas, después.
        ->and($posicionReglas)->toBeGreaterThan($posicionDueno);

    expect($prompt)->toContain('No prometas nada que no esté escrito arriba')
        ->and($prompt)->toContain('Tampoco si el cliente insiste');
});

it('sin instrucciones el prompt sigue funcionando, con los datos del negocio', function (): void {
    // No es obligatorio: quien no lo escriba tiene el bot que ya tenía.
    $ajustes = ajustesDelBot((int) $this->company->id);

    $prompt = app(BotPrompt::class)->paraPregunta($ajustes, 'a que hora abren');

    expect($prompt)->toContain('Abrimos de 8 a 8.')
        ->and($prompt)->not->toContain('QUIÉN ERES')
        ->and($prompt)->toContain('REGLAS QUE MANDAN');
});

// ---------------------------------------------------------------- Que no hable como una tienda

it('sin productos, el prompt no habla de listas de productos ni de HOY NO HAY', function (): void {
    /*
     * Un negocio que vende un servicio no tiene catálogo. Dejarle las reglas de tienda empuja al
     * modelo a comportarse como una, justo cuando se le está pidiendo que venda otra cosa.
     */
    $ajustes = ajustesDelBot((int) $this->company->id, ['instructions' => 'Vendes un sistema.']);

    $prompt = app(BotPrompt::class)->paraPregunta($ajustes, 'cuanto cuesta el sistema');

    expect($prompt)->not->toContain('HOY NO HAY')
        ->and($prompt)->not->toContain('lista de productos')
        ->and($prompt)->not->toContain('PRODUCTOS QUE COINCIDEN');
});

it('con productos sí aparecen ellos y sus dos reglas', function (): void {
    // El contraste: sin esto, «no menciona productos» podría cumplirse por no mencionarlos nunca.
    Product::create(['sku' => 'BAT-1', 'name' => 'Batida de lechosa', 'price' => '120.00']);
    $ajustes = ajustesDelBot((int) $this->company->id);

    $prompt = app(BotPrompt::class)->paraPregunta($ajustes, 'tienen batida?');

    expect($prompt)->toContain('Batida de lechosa')
        ->and($prompt)->toContain('PRODUCTOS QUE COINCIDEN')
        ->and($prompt)->toContain('HOY NO HAY');
});

// ---------------------------------------------------------------- Los planes

it('apagado, ni un precio de plan entra en el prompt', function (): void {
    Plan::create(['name' => 'Pro', 'slug' => 'pro-x', 'price' => '1500', 'billing_cycle' => 'monthly', 'is_active' => true]);
    $ajustes = ajustesDelBot((int) $this->company->id);

    $prompt = app(BotPrompt::class)->paraPregunta($ajustes, 'cuanto cuesta');

    expect($prompt)->not->toContain('PLANES Y PRECIOS')
        ->and($prompt)->not->toContain('1,500');
});

it('encendido, los planes salen de la tabla y no de un texto copiado a mano', function (): void {
    /*
     * De la tabla a propósito: copiados en el texto del negocio, el día que cambie un precio queda
     * una cifra vieja escrita en un campo del que nadie se acuerda, y el bot la sigue diciendo.
     */
    Plan::create(['name' => 'Básico', 'slug' => 'basico-x', 'price' => '750', 'billing_cycle' => 'monthly', 'is_active' => true]);
    Plan::create(['name' => 'Pro', 'slug' => 'pro-x', 'price' => '1500', 'billing_cycle' => 'monthly', 'is_active' => true]);

    $ajustes = ajustesDelBot((int) $this->company->id, ['includes_plans' => true]);

    $prompt = app(BotPrompt::class)->paraPregunta($ajustes, 'cuanto cuesta');

    expect($prompt)->toContain('PLANES Y PRECIOS')
        ->and($prompt)->toContain('Básico')
        ->and($prompt)->toContain('750')
        ->and($prompt)->toContain('Pro')
        ->and($prompt)->toContain('1,500');
});

// ---------------------------------------------------------------- La base de conocimiento

it('apagada, no se busca en los documentos aunque los haya', function (): void {
    $this->company->update(['modules' => ['whatsapp', 'ai']]);
    AiDocument::create(['title' => 'Folleto', 'content' => 'BM Business administra ventas e inventario.']);

    $ajustes = ajustesDelBot((int) $this->company->id);

    expect(app(BotPrompt::class)->paraPregunta($ajustes, 'que hace el sistema'))
        ->not->toContain('DE LA BASE DE CONOCIMIENTO');
});

it('sin el módulo de IA no se busca, aunque el interruptor esté encendido', function (): void {
    /*
     * El módulo se comprueba al construir el prompt y no solo al guardar: una empresa puede perderlo
     * al bajar de plan, y entonces esto tiene que dejar de buscar sin que nadie lo apague a mano.
     */
    $this->company->update(['modules' => ['whatsapp']]);
    AiDocument::create(['title' => 'Folleto', 'content' => 'BM Business administra ventas.']);

    $ajustes = ajustesDelBot((int) $this->company->id, ['uses_documents' => true]);

    expect(app(BotPrompt::class)->paraPregunta($ajustes, 'que hace el sistema'))
        ->not->toContain('DE LA BASE DE CONOCIMIENTO');
});

it('si el proveedor no sabe hacer embeddings, se contesta igual sin ese contexto', function (): void {
    /*
     * `AnthropicProvider::embed()` lanza a propósito: no ofrece ese servicio. Sin esta salvaguarda,
     * una empresa con Claude vería fallar CADA mensaje por intentar buscar en unos documentos que
     * nunca se pudieron indexar.
     */
    $this->company->update(['modules' => ['whatsapp', 'ai']]);
    AiDocument::create(['title' => 'Folleto', 'content' => 'BM Business administra ventas.']);

    app()->instance(AiProvider::class, new AnthropicProvider(['api_key' => 'x', 'chat_model' => 'y']));

    $ajustes = ajustesDelBot((int) $this->company->id, ['uses_documents' => true]);

    $prompt = app(BotPrompt::class)->paraPregunta($ajustes, 'que hace el sistema');

    // Peor respuesta, pero respuesta: el prompt existe y trae las reglas.
    expect($prompt)->not->toContain('DE LA BASE DE CONOCIMIENTO')
        ->and($prompt)->toContain('REGLAS QUE MANDAN');
});

// ---------------------------------------------------------------- Aislamiento

it('los documentos de otra empresa no entran nunca en este prompt', function (): void {
    $otra = empresaDelPrompt('Ajena');
    $otra->update(['modules' => ['whatsapp', 'ai']]);
    app()->make(RagService::class)->index('Secreto ajeno', 'La receta secreta de la competencia.');

    app(CurrentCompany::class)->set($this->company->id);
    $this->company->update(['modules' => ['whatsapp', 'ai']]);

    $ajustes = ajustesDelBot((int) $this->company->id, ['uses_documents' => true]);

    expect(app(BotPrompt::class)->paraPregunta($ajustes, 'receta secreta'))
        ->not->toContain('receta secreta de la competencia');
});
