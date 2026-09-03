<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\AI\Models\AiSetting;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\AI\Providers\GeminiProvider;
use App\Modules\AI\Providers\LocalAiProvider;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Los ajustes de IA de la plataforma.
 *
 * Hasta ahora la clave salía SOLO de variables de entorno, así que encender la IA exigía tocar el
 * `.env` de Vercel y volver a desplegar. Y sin clave, la pantalla enseñaba la plantilla enlatada del
 * proveedor local —con el prompt del sistema pegado dentro— como si fuera la respuesta.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'IA Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@ia.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->super = User::create([
        'company_id' => $this->company->id, 'name' => 'Operador',
        'email' => 'super@ia.test', 'password' => 'secret-password',
        'is_super_admin' => true,
    ]);
});

// ------------------------------------------------------------------------------ Quién entra

it('solo el operador de la plataforma entra a los ajustes de IA', function (): void {
    // La clave es de la plataforma: quien la vea, gasta de la cuota de todos.
    $this->actingAs($this->owner)->get(route('platform.ai'))->assertForbidden();
    $this->actingAs($this->super)->get(route('platform.ai'))->assertOk();
});

// ------------------------------------------------------------------------------ La clave

it('la clave se guarda cifrada y no queda legible en la tabla', function (): void {
    $this->actingAs($this->super)->put(route('platform.ai.update'), [
        'provider' => 'gemini',
        'api_key' => 'AIzaSyTOTALMENTEDEPRUEBA123456',
    ])->assertRedirect();

    $enBruto = (string) DB::table('ai_settings')->value('api_key');

    expect($enBruto)->not->toContain('AIzaSy')
        // Y sigue leyéndose bien desde el modelo.
        ->and(AiSetting::actual()->api_key)->toBe('AIzaSyTOTALMENTEDEPRUEBA123456');
});

it('la clave nunca se devuelve al HTML', function (): void {
    // Un campo relleno con la clave la deja en el código fuente de la página y en el historial.
    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaSySECRETO999']);

    $this->actingAs($this->super)->get(route('platform.ai'))
        ->assertOk()
        ->assertDontSee('AIzaSySECRETO999');
});

it('guardar sin tocar la clave la conserva', function (): void {
    // El formulario no la devuelve, así que un guardado normal llega sin ella: si eso la borrara,
    // cambiar el modelo de chat apagaría la IA sin avisar.
    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaSyLAQUEHABIA']);

    $this->actingAs($this->super)->put(route('platform.ai.update'), [
        'provider' => 'gemini',
        'chat_model' => 'gemini-2.0-flash',
    ]);

    expect(AiSetting::actual()->api_key)->toBe('AIzaSyLAQUEHABIA');
});

/*
 * EL TEST QUE FALTABA, Y POR CUYO HUECO SE COLÓ EL FALLO.
 *
 * El de aquí arriba —«guardar sin tocar la clave la conserva»— manda la petición SIN el campo
 * `api_key`, y así pasaba también con el código roto. Pero un navegador no hace eso: un campo de
 * contraseña vacío **se envía igualmente**, como cadena vacía. Esa es la petición de verdad, y era la
 * que borraba la clave.
 *
 * Y `ai_settings` no tiene `company_id`: es UNA fila para toda la plataforma. Así que cambiar el
 * modelo de chat, o subir el tope diario, dejaba sin IA a TODAS las empresas a la vez y sin decir
 * una palabra. Este test manda el formulario como lo manda el navegador.
 */
it('la clave sobrevive a un guardado normal, que es como la manda el navegador', function (): void {
    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaSyLAQUEHABIA']);

    $this->actingAs($this->super)->put(route('platform.ai.update'), [
        'provider' => 'gemini',
        'chat_model' => 'gemini-2.0-flash',
        'daily_limit' => 80,
        'api_key' => '',   // el campo va vacío en el envío, no ausente
    ]);

    expect(AiSetting::actual()->api_key)->toBe('AIzaSyLAQUEHABIA')
        ->and(AiSetting::actual()->configurado())->toBeTrue()
        // Y lo que sí se venía a cambiar, se cambió.
        ->and(AiSetting::actual()->chat_model)->toBe('gemini-2.0-flash')
        ->and((int) AiSetting::actual()->daily_limit)->toBe(80);
});

/*
 * Borrar sigue siendo posible, pero ahora hay que pedirlo. Quitar la clave apaga la IA de toda la
 * plataforma: es una decisión, no algo que deba ocurrir por omisión.
 */
it('marcar la casilla sí borra la clave y apaga la IA', function (): void {
    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaSyLAQUEHABIA']);

    $this->actingAs($this->super)->put(route('platform.ai.update'), [
        'provider' => 'gemini',
        'api_key' => '',
        'borrar_api_key' => '1',
    ]);

    expect(AiSetting::actual()->api_key)->toBeNull()
        ->and(AiSetting::actual()->configurado())->toBeFalse();
});

/*
 * El caso raro pero posible: escribir una clave nueva Y dejar marcada la casilla de borrar. Manda lo
 * que se escribió, porque teclear una clave es la intención más clara de las dos; quedarse sin nada
 * sería el peor de los dos resultados.
 */
it('si escribes una clave nueva, gana sobre la casilla de borrar', function (): void {
    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaSyLAVIEJA']);

    $this->actingAs($this->super)->put(route('platform.ai.update'), [
        'provider' => 'gemini',
        'api_key' => 'AIzaSyLANUEVA',
        'borrar_api_key' => '1',
    ]);

    expect(AiSetting::actual()->api_key)->toBe('AIzaSyLANUEVA');
});

// ------------------------------------------------------------------------------ Qué proveedor sale

it('con clave en la base se usa ese proveedor, no el del entorno', function (): void {
    // Es el motivo de todo esto: poder encenderla desde el panel en vez de redesplegar.
    AiSetting::actual()->update([
        'provider' => 'gemini', 'api_key' => 'AIzaSyX',
        'chat_model' => 'gemini-2.0-flash', 'embedding_model' => 'gemini-embedding-001',
    ]);

    app()->forgetInstance(AiProvider::class);

    expect(app(AiProvider::class))->toBeInstanceOf(GeminiProvider::class);
});

it('sin clave se queda en el proveedor local', function (): void {
    app()->forgetInstance(AiProvider::class);

    expect(app(AiProvider::class))->toBeInstanceOf(LocalAiProvider::class)
        ->and(app(AiProvider::class)->redactaRespuestas())->toBeFalse();
});

// ------------------------------------------------------------------------------ Probar la conexión

it('probar dice el motivo cuando la clave no sirve, y no revienta', function (): void {
    /*
     * Es lo que convierte «no funciona» en algo accionable. Y tiene que ser un aviso, no una página
     * de error: en todo el camino de la IA no había un solo try/catch.
     */
    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaSyMALA',
        'chat_model' => 'gemini-2.0-flash', 'embedding_model' => 'gemini-embedding-001']);
    app()->forgetInstance(AiProvider::class);

    Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);

    $this->actingAs($this->super)->post(route('platform.ai.test'))
        ->assertRedirect()
        ->assertSessionHas('panel_error');
});

it('probar confirma cuando todo va bien', function (): void {
    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaSyBUENA',
        'chat_model' => 'gemini-2.0-flash', 'embedding_model' => 'gemini-embedding-001',
        'embedding_dimensions' => 768]);
    app()->forgetInstance(AiProvider::class);

    // EL ORDEN IMPORTA: `Http::fake` casa por orden de declaración y compara la URL completa.
    Http::fake([
        '*:embedContent*' => Http::response(['embedding' => ['values' => array_fill(0, 768, 0.1)]]),
        '*:generateContent*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'listo']]]]]]),
    ]);

    $this->actingAs($this->super)->post(route('platform.ai.test'))
        ->assertSessionHas('panel_ok');
});

it('avisa de que Claude no puede indexar documentos', function (): void {
    // Su `embed()` lanza excepción. Con Claude, el asistente RAG no encuentra nada nunca.
    $this->actingAs($this->super)->get(route('platform.ai'))
        ->assertOk()
        ->assertSee('NO puede indexar documentos', false);
});
