<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Modules\SocialCommerce\Enums\TemplateChannel;
use App\Modules\SocialCommerce\Events\LeadCaptured;
use App\Modules\SocialCommerce\Models\ContactIdentity;
use App\Modules\SocialCommerce\Models\Conversation;
use App\Modules\SocialCommerce\Models\Message;
use App\Modules\SocialCommerce\Models\Rule;
use App\Modules\SocialCommerce\Models\RuleTemplateUsage;
use App\Modules\SocialCommerce\Models\Settings;
use App\Modules\SocialCommerce\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/*
 * Webhook propio de Social Commerce: mismo doble cierre que el resto de webhooks del proyecto
 * (token en la URL + firma HMAC), y la misma idempotencia que `polar_webhook_events`.
 *
 * Lo que se fija aquí es exactamente lo que un aviso duplicado o falsificado podría romper: que no
 * se creen dos veces el mismo contacto/conversación/mensaje, y que sin firma válida no entre nada.
 */

uses(RefreshDatabase::class);

/**
 * El aviso `message.received` tal como lo manda Zernio (misma forma que ya prueba
 * `tests/Feature/WhatsApp/WhatsAppZernioTest.php::avisoZernio()`, adaptado con los campos que
 * `WebhookEventProcessor` de verdad lee).
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function avisoSocialCommerce(string $plataforma, ?string $texto, string $direccion = 'incoming', array $extra = []): array
{
    return array_replace_recursive([
        'message' => [
            'platform' => $plataforma,
            'conversationId' => 'conv_abc',
            'platformMessageId' => 'wamid.XYZ',
            'direction' => $direccion,
            'text' => $texto,
            'sender' => ['id' => 'igsid_123', 'username' => 'ana.ig', 'name' => 'Ana'],
            'createdAt' => '2026-09-25T10:00:00Z',
        ],
        'account' => ['id' => 'acc_1'],
    ], $extra);
}

/**
 * Manda el aviso firmado, como haría Zernio. La firma va sobre el cuerpo CRUDO.
 *
 * @param  array<string, mixed>  $aviso
 */
function mandarAvisoSC(Settings $ajustes, array $aviso, ?string $firma = null): TestResponse
{
    $cuerpo = (string) json_encode($aviso, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return test()->call(
        'POST',
        route('webhooks.social-commerce', $ajustes->webhook_token),
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ZERNIO_SIGNATURE' => $firma ?? hash_hmac('sha256', $cuerpo, (string) $ajustes->webhook_secret),
        ],
        $cuerpo,
    );
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tienda'));
    $this->company->forceFill(['social_api_key' => str_repeat('a', 64)])->save();
    app(CurrentCompany::class)->set($this->company->id);

    $this->ajustes = Settings::paraEmpresa((int) $this->company->id);
});

// ---------------------------------------------------------------- Seguridad

it('rechaza un token que no corresponde a ninguna empresa', function (): void {
    test()->call('POST', route('webhooks.social-commerce', 'token-inventado'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ZERNIO_SIGNATURE' => 'lo-que-sea',
    ], '{}')->assertStatus(401);

    expect(WebhookEvent::count())->toBe(0);
});

it('rechaza un aviso sin firma', function (): void {
    mandarAvisoSC($this->ajustes, avisoSocialCommerce('instagram', 'precio'), firma: '')->assertStatus(401);

    expect(Conversation::count())->toBe(0);
});

it('rechaza una firma que no cuadra', function (): void {
    mandarAvisoSC($this->ajustes, avisoSocialCommerce('instagram', 'precio'), firma: hash_hmac('sha256', 'otra-cosa', 'otro-secreto'))
        ->assertStatus(401);

    expect(Conversation::count())->toBe(0);
});

// ---------------------------------------------------------------- Idempotencia

it('el mismo aviso repetido no crea la conversación ni el mensaje dos veces', function (): void {
    // Zernio reintenta lo que no responde 2xx a tiempo: el mismo aviso puede llegar más de una vez.
    $aviso = avisoSocialCommerce('instagram', '¿Cuánto cuesta?');

    mandarAvisoSC($this->ajustes, $aviso)->assertOk();
    mandarAvisoSC($this->ajustes, $aviso)->assertOk();

    expect(WebhookEvent::count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(1)
        ->and(ContactIdentity::count())->toBe(1);
});

it('dos avisos DISTINTOS de la misma conversación sí crean dos mensajes', function (): void {
    mandarAvisoSC($this->ajustes, avisoSocialCommerce('instagram', 'precio', extra: ['message' => ['platformMessageId' => 'msg_1']]))->assertOk();
    mandarAvisoSC($this->ajustes, avisoSocialCommerce('instagram', 'gracias', extra: ['message' => ['platformMessageId' => 'msg_2']]))->assertOk();

    expect(Conversation::count())->toBe(1) // misma conversación
        ->and(Message::count())->toBe(2); // dos mensajes distintos
});

// ---------------------------------------------------------------- Lo que hace con un aviso entrante

it('crea la identidad, la conversación y el mensaje, y dispara LeadCaptured en la primera vez', function (): void {
    Event::fake(LeadCaptured::class);

    mandarAvisoSC($this->ajustes, avisoSocialCommerce('instagram', 'Buenas, ¿cuánto cuesta?'))->assertOk();

    $identidad = ContactIdentity::first();
    expect($identidad->external_id)->toBe('igsid_123')
        ->and($identidad->external_username)->toBe('ana.ig')
        ->and($identidad->customer_id)->toBeNull(); // sin evidencia de teléfono, no se enlaza a ciegas

    $conversacion = Conversation::first();
    expect($conversacion->zernio_conversation_id)->toBe('conv_abc')
        ->and($conversacion->contact_identity_id)->toBe($identidad->id);

    expect(Message::first()->body)->toBe('Buenas, ¿cuánto cuesta?');

    Event::assertDispatched(LeadCaptured::class, fn (LeadCaptured $e): bool => $e->conversation->is($conversacion));
});

it('un segundo mensaje en una conversación que ya existía no dispara LeadCaptured otra vez', function (): void {
    mandarAvisoSC($this->ajustes, avisoSocialCommerce('instagram', 'precio', extra: ['message' => ['platformMessageId' => 'msg_1']]))->assertOk();

    Event::fake(LeadCaptured::class);
    mandarAvisoSC($this->ajustes, avisoSocialCommerce('instagram', 'otra pregunta', extra: ['message' => ['platformMessageId' => 'msg_2']]))->assertOk();

    Event::assertNotDispatched(LeadCaptured::class);
});

it('ignora en silencio (sin error) un aviso de una plataforma que no soporta', function (): void {
    mandarAvisoSC($this->ajustes, avisoSocialCommerce('whatsapp', 'hola'))->assertOk();

    expect(Conversation::count())->toBe(0)
        ->and(WebhookEvent::first()->result)->toBe(WebhookEvent::RESULT_APPLIED);
});

// ---------------------------------------------------------------- Uso de plantillas (saliente)

it('registra qué plantilla se usó cuando el aviso es de un mensaje SALIENTE que coincide', function (): void {
    $product = Product::create(['sku' => 'CAM-1', 'name' => 'Camisa Nike Air', 'cost' => '800', 'price' => '1500']);
    $rule = Rule::factory()->create(['product_id' => $product->id, 'status' => RuleStatus::Active]);
    $plantilla = $rule->templates()->create(['channel' => TemplateChannel::Dm->value, 'body' => 'La {producto} cuesta {precio}.', 'position' => 0]);

    $textoQueEnviaríaZernio = 'La Camisa Nike Air cuesta DOP 1,500.00.';

    mandarAvisoSC($this->ajustes, avisoSocialCommerce('instagram', $textoQueEnviaríaZernio, direccion: 'outgoing'))->assertOk();

    expect(RuleTemplateUsage::count())->toBe(1)
        ->and(RuleTemplateUsage::first()->rule_template_id)->toBe($plantilla->id)
        // Un mensaje saliente NO es un contacto entrante: no se crea conversación por esto.
        ->and(Conversation::count())->toBe(0);
});

it('un mensaje saliente que no coincide con ninguna plantilla no revienta ni registra nada', function (): void {
    mandarAvisoSC($this->ajustes, avisoSocialCommerce('instagram', 'un mensaje escrito a mano por el dueño', direccion: 'outgoing'))
        ->assertOk();

    expect(RuleTemplateUsage::count())->toBe(0);
});
