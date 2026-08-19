<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\AI\Models\AiDocumentChunk;
use App\Modules\AI\Models\AiSetting;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\AI\Services\RagService;
use App\Modules\AI\Support\Vector;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
 * El proveedor de Gemini y el candado de las dimensiones.
 *
 * Lo segundo es lo que podía corromperse EN SILENCIO: los vectores de proveedores distintos viven en
 * espacios distintos y ni siquiera miden lo mismo, y `Vector::cosine` recortaba al más corto sin
 * quejarse. Comparaba los primeros 128 componentes de dos espacios sin relación y devolvía un número
 * con pinta de similitud, que la pantalla pintaba como «% afín».
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Gemini Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@gemini.test', 'password' => 'secret-password',
    ]), 'owner');

    AiSetting::actual()->update([
        'provider' => 'gemini',
        'api_key' => 'AIzaSyDEPRUEBA',
        'chat_model' => 'gemini-2.0-flash',
        'embedding_model' => 'gemini-embedding-001',
        'embedding_dimensions' => 8,
    ]);

    app()->forgetInstance(AiProvider::class);
});

/**
 * Zernio con las formas que devuelve Gemini de verdad.
 *
 * EL ORDEN IMPORTA: `Http::fake` casa por orden de declaración y compara la URL COMPLETA, así que
 * los patrones llevan `*` a los dos lados.
 */
function geminiResponde(int $dimensiones = 8, string $texto = 'listo'): void
{
    Http::fake([
        '*:embedContent*' => Http::response(['embedding' => ['values' => array_fill(0, $dimensiones, 0.25)]]),
        '*:generateContent*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $texto]]]]]]),
    ]);
}

// ------------------------------------------------------------------- Cómo habla con Google

it('manda la clave en la cabecera y NO en la URL', function (): void {
    /*
     * Google documenta también `?key=...`, y es mala idea: una clave en la barra de direcciones
     * acaba en los registros de acceso del servidor y en cualquier informe de error con la URL.
     */
    geminiResponde();

    app(AiProvider::class)->embed('hola');

    Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'AIzaSyDEPRUEBA')
        && ! str_contains($request->url(), 'key='));
});

it('pide el número de dimensiones que tiene guardado', function (): void {
    // Si no se pidiera, el tamaño lo decidiría el modelo y podría cambiar sin avisar: todo lo ya
    // indexado dejaría de casar en silencio.
    geminiResponde();

    app(AiProvider::class)->embed('hola');

    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'embedContent')
        || ($request->data()['outputDimensionality'] ?? null) === 8);
});

it('lee la respuesta del sitio donde Gemini la pone', function (): void {
    geminiResponde(texto: 'la política es de 30 días');

    expect(app(AiProvider::class)->chat([['role' => 'user', 'content' => '¿?']]))
        ->toBe('la política es de 30 días');
});

it('traduce el rol del sistema al formato de Gemini', function (): void {
    // Gemini separa la instrucción del hilo y llama «model» a lo que otros llaman «assistant».
    geminiResponde();

    app(AiProvider::class)->chat([
        ['role' => 'system', 'content' => 'Eres breve'],
        ['role' => 'user', 'content' => 'hola'],
    ]);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'generateContent')) {
            return true;
        }

        return ($request->data()['system_instruction']['parts'][0]['text'] ?? null) === 'Eres breve'
            && ($request->data()['contents'][0]['role'] ?? null) === 'user';
    });
});

// ------------------------------------------------------------------- El candado de las dimensiones

it('no compara vectores de distinto tamaño: se niega en vez de inventarse un número', function (): void {
    expect(fn () => Vector::cosine([1.0, 2.0], [1.0, 2.0, 3.0]))
        ->toThrow(InvalidArgumentException::class);
});

it('lo indexado guarda con qué proveedor se hizo', function (): void {
    geminiResponde();

    app(RagService::class)->index('Envíos', 'Los envíos tardan dos días hábiles en llegar.');

    $chunk = AiDocumentChunk::query()->firstOrFail();

    expect($chunk->provider)->toBe('gemini')->and($chunk->dimensions)->toBe(8);
});

it('lo indexado con otro proveedor NO se usa al buscar', function (): void {
    /*
     * Antes sí se usaba, y ese es el fallo: se comparaba contra vectores de otro espacio y salían
     * resultados con su porcentaje y todo, calculados sobre ruido.
     */
    geminiResponde();
    app(RagService::class)->index('Envíos', 'Los envíos tardan dos días hábiles en llegar.');

    // Llega alguien y cambia el proveedor.
    AiSetting::actual()->update(['provider' => 'openai', 'embedding_dimensions' => 8]);

    expect(app(RagService::class)->retrieve('envíos'))->toBeEmpty();
});

it('cuenta cuántos fragmentos quedaron desfasados', function (): void {
    // Sin la cuenta, el asistente contestaría «no encontré nada» y nadie sabría por qué.
    geminiResponde();
    app(RagService::class)->index('Envíos', 'Los envíos tardan dos días hábiles en llegar.');

    AiSetting::actual()->update(['provider' => 'openai']);

    expect(app(RagService::class)->desfasados())->toBeGreaterThan(0);
});

it('reindexar los convierte al proveedor de ahora', function (): void {
    geminiResponde();
    app(RagService::class)->index('Envíos', 'Los envíos tardan dos días hábiles en llegar.');

    // Se vuelve a Gemini tras haber pasado por otro: lo indexado sigue marcado como del anterior.
    AiDocumentChunk::query()->update(['provider' => 'openai', 'dimensions' => 1536]);

    $resultado = app(RagService::class)->reindexar();

    expect($resultado['convertidos'])->toBe(1)
        ->and($resultado['quedan'])->toBe(0)
        ->and(AiDocumentChunk::query()->firstOrFail()->provider)->toBe('gemini');
});

// ------------------------------------------------------------------- Que no reviente

it('un fallo del proveedor da aviso y no una página 500', function (): void {
    // En todo el camino de la IA no había un solo try/catch: cualquier fallo era una página de error.
    Http::fake(['*' => Http::response(['error' => ['message' => 'quota exceeded']], 429)]);

    $this->actingAs($this->owner)
        ->post(route('panel.ai.ask'), ['query' => '¿cuánto tardan los envíos?'])
        ->assertRedirect()
        ->assertSessionHas('panel_error');
});

it('indexar con el proveedor caído tampoco revienta', function (): void {
    Http::fake(['*' => Http::response([], 500)]);

    $this->actingAs($this->owner)
        ->post(route('panel.ai.documents.store'), [
            '_form' => 'ai_document',
            'title' => 'Envíos',
            'content' => 'Los envíos tardan dos días hábiles.',
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_error');
});
