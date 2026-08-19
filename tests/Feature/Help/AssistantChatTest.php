<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\AI\Models\AiSetting;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Help\Models\AssistantQuestion;
use App\Modules\Help\Services\AssistantAnswerer;
use App\Modules\Help\Services\HelpAnswerer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
 * El asistente de la burbuja.
 *
 * Lo que se comprueba aquí no es que «responda»: eso ya lo cubre HelpAssistantTest con dieciséis
 * preguntas reales. Aquí está lo que se le añadió encima y lo que puede costar dinero o decir
 * mentiras: quién puede preguntar, qué pasa al pasarse del tope, y qué se contesta cuando lo que
 * responde la pregunta es algo que ese usuario no puede ver.
 */

uses(RefreshDatabase::class);

/**
 * Empresa con un plan concreto, para poder dejar módulos FUERA a propósito.
 *
 * @param  list<string>|null  $modulos
 * @return array{0: Company, 1: User}
 */
function empresaConAsistente(string $nombre, ?array $modulos, string $correo): array
{
    app(CurrentCompany::class)->forget();

    $company = app(CompanyService::class)->create(new CreateCompanyData(name: $nombre));
    $company->update(['ai_assistant' => true]);

    $plan = Plan::create([
        'name' => 'Plan '.$nombre, 'slug' => 'plan-'.str($nombre)->slug()->toString(),
        'price' => '1000', 'billing_cycle' => 'monthly', 'trial_days' => 15,
        'modules' => $modulos, 'is_active' => true,
    ]);

    app(SubscriptionService::class)->startSelfServiceTrial($company, $plan, 15);

    app(CurrentCompany::class)->set($company->id);

    $usuario = withRole(User::create([
        'company_id' => $company->id, 'name' => 'Duena', 'email' => $correo,
        'password' => 'secret-password',
    ]), 'owner');

    return [$company->fresh(), $usuario];
}

/**
 * Deja el asistente hablando con Gemini de verdad (falseado), no con el proveedor local.
 *
 * Hace falta para cualquier test sobre el GASTO: el proveedor local no sale a la red nunca, así que
 * sobre él «no se llamó a nadie» es cierto siempre y no demuestra nada.
 *
 * El `forgetInstance` es imprescindible: `AiProvider` está registrado como singleton y ya se resolvió
 * —al local— antes de que este test cambiara los ajustes.
 */
function conGemini(): void
{
    Http::fake([
        '*:generateContent*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Pues mira']]]]]]),
    ]);

    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaDePrueba1234567890']);

    app()->forgetInstance(AiProvider::class);
    app()->forgetInstance(HelpAnswerer::class);
    app()->forgetInstance(AssistantAnswerer::class);
}

beforeEach(function (): void {
    [$this->company, $this->owner] = empresaConAsistente(
        'Con Entregas', ['pos', 'sales', 'delivery', 'inventory'], 'duena@conentregas.test',
    );
});

// ---------------------------------------------------------------- Quién puede preguntar

it('con el interruptor apagado el endpoint responde 403, no solo esconde el botón', function (): void {
    // Ocultar la burbuja no cierra nada: la dirección se puede llamar desde la consola del navegador,
    // y entonces una empresa a la que se le apagó seguiría gastando cuota del operador.
    $this->company->update(['ai_assistant' => false]);

    $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como anulo una venta'])
        ->assertForbidden();
});

it('una empresa con la suscripción vencida no puede preguntar, aunque esté encendido', function (): void {
    // Si conservara el asistente, seguiría costando dinero mientras no paga.
    $this->company->subscription->update(['status' => 'suspended']);

    $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como anulo una venta'])
        ->assertForbidden();
});

it('encendido y al día, contesta', function (): void {
    $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como anulo una venta'])
        ->assertOk()
        ->assertJsonPath('agotada', false)
        ->assertJsonPath('motivo', null);
});

// ------------------------------------------------- Lo que no está en el plan, y lo que sí

it('si el plan no incluye el módulo, lo dice en vez de callar', function (): void {
    // Antes desaparecía en silencio y contestaba «no lo encuentro», que es falso: el artículo SÍ
    // responde su pregunta. Lo que pasa es otra cosa, y esa otra cosa se puede decir.
    [, $duena] = empresaConAsistente('Sin Entregas', ['pos', 'sales'], 'duena@sinentregas.test');

    $respuesta = $this->actingAs($duena)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como marco una entrega como entregada'])
        ->assertOk()
        ->assertJsonPath('motivo', 'modulo');

    expect($respuesta->json('respuesta'))->toContain('no está incluido en el plan');
});

it('y la misma pregunta sí se contesta a quien sí lo tiene', function (): void {
    // El contraste es el test: sin él, «no está en tu plan» podría ser la respuesta a todo.
    $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como marco una entrega como entregada'])
        ->assertOk()
        ->assertJsonPath('motivo', null);
});

it('al cajero sin permiso se le manda al dueño, y NO se le habla de planes', function (): void {
    // Su empresa ya lo tiene contratado. Ofrecerle cambiar de plan es mandarlo a molestar por una
    // decisión que ni es suya ni hace falta.
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@conentregas.test', 'password' => 'secret-password',
    ]), 'staff');

    $respuesta = $this->actingAs($cajero)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como anulo una venta'])
        ->assertOk()
        ->assertJsonPath('motivo', 'permiso');

    expect($respuesta->json('respuesta'))
        ->toContain('no tiene permiso')
        ->not->toContain('plan de tu empresa');
});

// ---------------------------------------------------------------------- El tope diario

it('al agotar el tope se avisa y NO se llama al proveedor', function (): void {
    /*
     * Con Gemini de verdad configurado, y NO con el proveedor local.
     *
     * Es la diferencia entre probar algo y no probar nada: el proveedor local no sale a la red jamás,
     * así que `assertNothingSent` pasaba igual con tope y sin él. Comprobado quitando el guardián: el
     * test seguía en verde. Aquí la única forma de que no salga ninguna petición es que el tope la
     * haya cortado antes.
     */
    conGemini();

    AiSetting::query()->update(['daily_limit' => 2]);

    for ($i = 0; $i < 2; $i++) {
        $this->actingAs($this->owner)
            ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como anulo una venta'])->assertOk();
    }

    // Las dos primeras SÍ salieron: es lo que da sentido a lo que viene.
    Http::assertSentCount(2);

    $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como cierro la caja'])
        ->assertOk()
        ->assertJsonPath('agotada', true)
        ->assertJsonPath('restantes', 0);

    // Lo que de verdad protege el bolsillo: la tercera no llegó a salir.
    Http::assertSentCount(2);
});

it('el tope cuenta por empresa: una no se come la cuota de la otra', function (): void {
    AiSetting::actual()->update(['daily_limit' => 1]);

    [, $otraDuena] = empresaConAsistente('Otra', ['pos', 'sales'], 'duena@otra.test');

    $this->actingAs($otraDuena)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como anulo una venta'])->assertOk();

    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como anulo una venta'])
        ->assertOk()
        ->assertJsonPath('agotada', false);
});

// ------------------------------------------------------------ El registro de lo preguntado

it('una pregunta que el manual no cubre se guarda como tal', function (): void {
    // Es la lista de qué artículo falta escribir. Sin esto no se descubre nunca: el usuario pregunta,
    // no encuentra, y se calla.
    $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'cuanto pesa un elefante africano'])
        ->assertOk();

    expect(AssistantQuestion::query()->where('answered_by', AssistantQuestion::SIN_RESPUESTA)->count())
        ->toBe(1);
});

it('cada pregunta queda con su empresa y su usuario', function (): void {
    $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como anulo una venta'])->assertOk();

    $fila = AssistantQuestion::query()->first();

    expect($fila->company_id)->toBe($this->company->id)
        ->and($fila->user_id)->toBe($this->owner->id);
});

// ---------------------------------------------------------------------------- El hilo

it('el hilo se recuerda entre preguntas y se puede borrar', function (): void {
    $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como anulo una venta'])->assertOk();

    expect(session()->get('asistente.hilo.'.$this->company->id))->toHaveCount(1);

    $this->actingAs($this->owner)->deleteJson(route('panel.assistant.reset'))->assertOk();

    expect(session()->get('asistente.hilo.'.$this->company->id))->toBeNull();
});

// -------------------------------------------------- La empresa activa no es la del usuario

it('el operador que entra al panel de un cliente pregunta como esa empresa', function (): void {
    /*
     * Este test existe porque el fallo ocurrió de verdad, y ninguno de los de arriba lo vio.
     *
     * En todos ellos el usuario pertenece a la empresa que está mirando, así que tomar la empresa del
     * usuario o de la sesión daba lo mismo. El operador de la plataforma NO: entra al panel de un
     * cliente conservando su propio `company_id` —o ninguno—, y la pregunta moría con una violación
     * de clave foránea contra una empresa que no existe. Se descubrió probando en el navegador.
     */
    $superadmin = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'operador@bmos.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($superadmin)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'como anulo una venta'])
        ->assertOk();

    // Queda apuntada a la empresa que estaba mirando, no a la suya (que no tiene).
    expect(AssistantQuestion::query()->withoutGlobalScopes()->value('company_id'))
        ->toBe($this->company->id);
});

// ------------------------------------------------------------------------ Validación

it('no se acepta una pregunta vacía', function (): void {
    $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => ''])
        ->assertJsonValidationErrors('pregunta');
});

// -------------------------------------------------------- Hablar como una persona

/*
 * «Hola» no es una duda, pero es lo primero que escribe casi todo el mundo al abrir un chat.
 *
 * Antes contestaba «No encontré nada sobre eso en el manual» —visto en pantalla— y «cómo estás» se
 * iba al artículo de PRÉSTAMOS, porque «estas» se parecía a algo suyo. Las dos son la respuesta que
 * hace que alguien cierre la ventana en su primera frase, sin haber preguntado todavía.
 */

it('saluda de vuelta en vez de decir que no encuentra nada', function (): void {
    $r = $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'hola'])
        ->assertOk();

    expect($r->json('respuesta'))
        ->toContain('Hola')
        ->not->toContain('No encontré');
});

it('el saludo no se busca en el manual ni cuesta una llamada al proveedor', function (): void {
    // Serían las preguntas más frecuentes y las más inútiles de pagar.
    conGemini();

    $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'buenas!!'])->assertOk();

    Http::assertNothingSent();
});

it('una pregunta educada NO se traga por el saludo', function (): void {
    /*
     * El test que sostiene todo lo demás. La detección es por igualdad y no por «contiene»: si fuera
     * por «contiene», cualquiera que empezara con «hola» recibiría un saludo en vez de su respuesta y
     * tendría que repetir la pregunta sin los buenos días.
     */
    $r = $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'hola, como anulo una venta'])
        ->assertOk();

    expect($r->json('articulo.titulo'))->toBe('Anular una venta');
});

it('al preguntarle qué sabe hacer, responde con los módulos de ESA empresa', function (): void {
    // Ofrecerle ayuda con las Entregas a quien no las contrató es la misma mentira que explicárselas.
    [, $duena] = empresaConAsistente('Solo Caja', ['pos', 'sales'], 'duena@solocaja.test');

    $respuesta = $this->actingAs($duena)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'que puedes hacer'])
        ->assertOk()->json('respuesta');

    expect($respuesta)->toContain('Punto de Venta')->not->toContain('Entregas');
});

it('los saludos no ensucian la lista de huecos del manual', function (): void {
    // Cien «hola» no significan que falte un artículo sobre saludar.
    $this->actingAs($this->owner)
        ->postJson(route('panel.assistant.ask'), ['pregunta' => 'gracias'])->assertOk();

    expect(AssistantQuestion::query()->where('answered_by', AssistantQuestion::SIN_RESPUESTA)->count())
        ->toBe(0)
        ->and(AssistantQuestion::query()->where('answered_by', AssistantQuestion::SOCIAL)->count())
        ->toBe(1);
});
