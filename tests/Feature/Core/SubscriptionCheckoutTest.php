<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
 * Botón de pago de la suscripción.
 *
 * Abre el cobro en la pasarela y manda al cliente allí. Lo que NO hace —y es lo importante— es
 * activar nada: eso lo hace el aviso de pago de Polar cuando el dinero entra de verdad. La
 * dirección de retorno la puede escribir cualquiera en la barra del navegador, así que activar al
 * volver sería regalar el plan a quien la teclee.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();

    config(['polar.access_token' => 'polar_oat_de_prueba', 'polar.server' => 'sandbox']);

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(
        name: 'Heladería', email: 'duena@heladeria.example.com',
    ));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@heladeria.example.com', 'password' => 'secret-password',
    ]), 'owner');

    $this->plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => '1500', 'billing_cycle' => 'monthly',
        'trial_days' => 15, 'modules' => null, 'is_active' => true,
        'polar_product_id' => 'ad5bee12-beb1-48ee-b6ec-1eb5c9d1b6fe',
    ]);

    app(SubscriptionService::class)->subscribe($this->company, $this->plan);
});

/** Respuesta de Polar cuando el cobro se abre bien. */
function polarAbreCobro(string $url = 'https://sandbox.polar.sh/checkout/polar_c_abc'): void
{
    Http::fake(['*/v1/checkouts/' => Http::response(['id' => 'chk_1', 'url' => $url], 201)]);
}

it('el botón lleva a la pasarela de pago', function (): void {
    polarAbreCobro();

    $this->actingAs($this->owner)
        ->post(route('panel.account.checkout', $this->plan))
        ->assertRedirect('https://sandbox.polar.sh/checkout/polar_c_abc');
});

it('adjunta la empresa al cobro, que es como se sabe a quién activar', function (): void {
    // Sin este dato, el aviso de pago tendría que adivinar la empresa por el correo del comprador.
    polarAbreCobro();

    $this->actingAs($this->owner)->post(route('panel.account.checkout', $this->plan));

    Http::assertSent(function ($request): bool {
        $cuerpo = $request->data();

        return $cuerpo['metadata']['company_id'] === (string) $this->company->id
            && $cuerpo['products'] === ['ad5bee12-beb1-48ee-b6ec-1eb5c9d1b6fe'];
    });
});

it('abrir el cobro NO activa la suscripción', function (): void {
    // Solo se abre la puerta. Quien confirma el dinero es el webhook.
    polarAbreCobro();

    $this->actingAs($this->owner)->post(route('panel.account.checkout', $this->plan));

    expect($this->company->subscription->fresh()->isTrialing())->toBeTrue();
});

it('volver de la pasarela tampoco activa nada', function (): void {
    // Esta dirección se puede teclear a mano: si activara, el plan sería gratis para quien la sepa.
    $this->actingAs($this->owner)
        ->get(route('panel.account', ['pago' => 'recibido']))
        ->assertOk()
        ->assertSee('Estamos confirmándolo', false);

    expect($this->company->subscription->fresh()->isTrialing())->toBeTrue();
});

it('no ofrece pagar un plan sin enlazar con la pasarela', function (): void {
    $this->plan->update(['polar_product_id' => null]);

    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertDontSee('Activar mi plan');

    // Y tampoco por la puerta de atrás: ocultar el botón no basta.
    $this->actingAs($this->owner)
        ->post(route('panel.account.checkout', $this->plan))
        ->assertRedirect();

    expect(session('panel_error'))->toContain('no se puede contratar en línea');
});

it('sin pasarela configurada la pantalla solo ofrece contacto', function (): void {
    config(['polar.access_token' => null]);

    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertDontSee('Activar mi plan');
});

it('avisa en vez de romperse si la pasarela falla', function (): void {
    // Un fallo de Polar no puede dejar al cliente ante un error 500 sin saber qué hacer.
    Http::fake(['*/v1/checkouts/' => Http::response(['detail' => 'algo falló'], 422)]);

    $this->actingAs($this->owner)
        ->post(route('panel.account.checkout', $this->plan))
        ->assertRedirect();

    expect(session('panel_error'))->toContain('No pudimos abrir la pasarela');
});

it('un cajero no puede iniciar el pago de la empresa', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@heladeria.example.com', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->post(route('panel.account.checkout', $this->plan))->assertForbidden();
});

it('la pantalla muestra el botón con el precio del plan', function (): void {
    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertSee('Activar mi plan')
        ->assertSee('1,500.00');
});

// ------------------------------------------------------------ Cobro sin salir del panel

/*
 * El pago ocurre ahora en una ventana sobre la propia pantalla en vez de mandar al cliente a
 * polar.sh. Es la misma ruta y el mismo permiso: solo cambia cómo responde.
 *
 * El formulario de siempre SIGUE VIVO debajo. No es un resto del pasado: es una pantalla de pago, y
 * si el JavaScript no carga, el fallo se mide en dinero.
 */

it('devuelve la dirección del cobro cuando se le pide en JSON', function (): void {
    polarAbreCobro();

    $this->actingAs($this->owner)
        ->postJson(route('panel.account.checkout', $this->plan))
        ->assertOk()
        ->assertJson(['url' => 'https://sandbox.polar.sh/checkout/polar_c_abc']);
});

it('el cobro incrustado sigue adjuntando la empresa', function (): void {
    // Es la pieza que sostiene la integración entera. Si se perdiera, el pago entraría y no activaría
    // a nadie: dinero cobrado y cliente sin acceso, sin nada en pantalla que lo explique.
    polarAbreCobro();

    $this->actingAs($this->owner)->postJson(route('panel.account.checkout', $this->plan));

    Http::assertSent(fn ($request): bool => $request->data()['metadata']['company_id'] === (string) $this->company->id);
});

it('abrir el cobro en JSON tampoco activa nada', function (): void {
    polarAbreCobro();

    $this->actingAs($this->owner)->postJson(route('panel.account.checkout', $this->plan));

    expect($this->company->subscription->fresh()->isTrialing())->toBeTrue();
});

it('los motivos de fallo se dicen igual por JSON', function (string $preparar, string $esperado): void {
    // Sin esta simetría, un plan sin enlazar daría un aviso legible por el formulario y una ventana
    // en blanco por el otro camino: el cliente no sabría si es su conexión, su tarjeta o el sistema.
    match ($preparar) {
        'sin_producto' => $this->plan->update(['polar_product_id' => null]),
        'sin_pasarela' => config(['polar.access_token' => null]),
        'pasarela_caida' => Http::fake(['*/v1/checkouts/' => Http::response(['detail' => 'nope'], 422)]),
        // `ownerUser()` devuelve el primer usuario ACTIVO, y de ahí sale el correo del comprador. Sin
        // usuario activo y sin correo en la empresa, no hay a quién facturarle. (`actingAs` no pasa
        // por el inicio de sesión, así que el permiso sigue en pie: se prueba el guarda, no el login.)
        'sin_correo' => tap($this->company)->update(['email' => null])
            ->users()->update(['is_active' => false]),
    };

    $respuesta = $this->actingAs($this->owner)
        ->postJson(route('panel.account.checkout', $this->plan))
        ->assertStatus(422);

    expect($respuesta->json('message'))->toContain($esperado);
})->with([
    ['sin_producto', 'no se puede contratar en línea'],
    ['sin_pasarela', 'no se puede contratar en línea'],
    ['pasarela_caida', 'No pudimos abrir la pasarela'],
    ['sin_correo', 'correo de contacto'],
]);

it('un cajero tampoco puede abrirlo por JSON', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero2@heladeria.example.com', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->postJson(route('panel.account.checkout', $this->plan))->assertForbidden();
});

it('el botón de pago sigue siendo un formulario de verdad', function (): void {
    // Lo que garantiza que se puede pagar sin JavaScript. Se cuentan aperturas y cierres porque un
    // <form> dentro de otro es HTML inválido y el navegador desmonta el interior en silencio: ya pasó
    // una vez en «Mi empresa» y los tests de entonces no lo vieron.
    $html = $this->actingAs($this->owner)->get(route('panel.account'))->assertOk()->getContent();

    expect(substr_count($html, '<form'))->toBe(substr_count($html, '</form>'))
        ->and($html)->toContain('action="'.route('panel.account.checkout', $this->plan).'"')
        ->and($html)->toContain('method="POST"');
});

// ------------------------------------------------------------ El estado, para enterarse solo

it('el estado dice si el plan da acceso, y no lo cambia', function (): void {
    // Consultarlo mil veces no acerca ni un paso a tener el plan activo: quien decide es el webhook.
    $antes = $this->company->subscription->fresh()->status;

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($this->owner)->getJson(route('panel.account.status'))
            ->assertOk()
            ->assertJson(['activa' => true, 'prueba' => true, 'plan' => 'Pro']);
    }

    expect($this->company->subscription->fresh()->status)->toBe($antes);
});

it('el estado dice que no cuando la suscripción ya no da acceso', function (): void {
    $this->company->subscription->update(['trial_ends_at' => now()->subDay()]);

    $this->actingAs($this->owner)->getJson(route('panel.account.status'))
        ->assertOk()
        ->assertJson(['activa' => false]);
});

it('el estado sigue respondiendo con la suscripción vencida', function (): void {
    // Es justo cuando hace falta: alguien acaba de pagar para reactivarla. Si el middleware de
    // suscripción lo cerrara, el sondeo nunca vería la activación.
    $this->company->subscription->update(['trial_ends_at' => now()->subDays(30)]);

    $this->actingAs($this->owner)->getJson(route('panel.account.status'))->assertOk();
});

it('un cajero no puede consultar el estado de la suscripción', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero3@heladeria.example.com', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->getJson(route('panel.account.status'))->assertForbidden();
});

it('el estado no se filtra entre empresas', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena'));
    $ajeno = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Ajeno',
        'email' => 'ajeno@ajena.example.com', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($ajeno)->getJson(route('panel.account.status'))
        ->assertOk()
        ->assertJsonMissing(['plan' => 'Pro']);
});

it('sin JavaScript el motivo del fallo también se ve', function (): void {
    // Los avisos del sistema los pinta SweetAlert, así que sin JavaScript no se ve ninguno. En
    // cualquier otra pantalla es una molestia; aquí sería «pulso y no pasa nada» en el único sitio
    // donde el cliente paga, y justo cuando el camino de reserva es lo único que le queda.
    Http::fake(['*/v1/checkouts/' => Http::response(['detail' => 'nope'], 422)]);

    $this->actingAs($this->owner)->post(route('panel.account.checkout', $this->plan));

    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertSee('<noscript>', false)
        ->assertSee('No pudimos abrir la pasarela');
});

// ------------------------------------------------------------ Idioma de la pantalla de pago

it('no manda el idioma mientras nadie lo configure', function (): void {
    // Es una función en BETA de Polar que hay que habilitar en la organización. Mandarla a ciegas
    // arriesgaría que Polar rechazara la petición y dejaran de funcionar TODOS los cobros, así que
    // viene apagada y se enciende a sabiendas.
    config(['polar.locale' => null]);
    polarAbreCobro();

    $this->actingAs($this->owner)->post(route('panel.account.checkout', $this->plan));

    Http::assertSent(fn ($request): bool => ! array_key_exists('locale', $request->data()));
});

it('manda el idioma cuando se configura', function (): void {
    config(['polar.locale' => 'es']);
    polarAbreCobro();

    $this->actingAs($this->owner)->post(route('panel.account.checkout', $this->plan));

    Http::assertSent(fn ($request): bool => ($request->data()['locale'] ?? null) === 'es');
});

it('el idioma no desplaza lo que sostiene la integración', function (): void {
    // El `metadata` es lo que dice a qué empresa activar. Añadir campos al cobro no puede perderlo.
    config(['polar.locale' => 'es']);
    polarAbreCobro();

    $this->actingAs($this->owner)->post(route('panel.account.checkout', $this->plan));

    Http::assertSent(fn ($request): bool => $request->data()['metadata']['company_id'] === (string) $this->company->id
        && $request->data()['products'] === ['ad5bee12-beb1-48ee-b6ec-1eb5c9d1b6fe']);
});

// ------------------------------------------------------- Apple Pay y Google Pay

/*
 * Los monederos no se pueden encender desde aquí.
 *
 * Polar los trae APAGADOS en el pago embebido —«wallet payment methods are not enabled when you
 * embed our checkout form into your website»— y hay que pedirle que autorice el dominio, por correo.
 * En su propia página salen solos, según el dispositivo del cliente.
 *
 * De ahí el segundo camino: mismo cobro, misma ruta, pero saliendo a Polar. Lo que se comprueba aquí
 * es que sea de verdad el MISMO cobro y no un atajo que se salte lo que el otro sí hace.
 */

it('ofrece pagar con monedero, diciendo que se sale a Polar', function (): void {
    // Sin decir a dónde lleva, el cliente pulsa creyendo que se queda y acaba en un dominio que no
    // reconoce, en el único momento en que está poniendo su tarjeta.
    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertSee('Pagar con Apple Pay o Google Pay')
        ->assertSee('Se abre la página de Polar');
});

it('el camino del monedero no es un botón suelto fuera del formulario', function (): void {
    // Usa el mismo <form> que el pago normal: si quedara fuera, no llevaría el token y el cobro se
    // rechazaría justo cuando el cliente ya decidió pagar.
    $html = $this->actingAs($this->owner)->get(route('panel.account'))->assertOk()->getContent();

    $inicio = strpos($html, 'action="'.route('panel.account.checkout', $this->plan).'"');
    $fin = strpos($html, '</form>', $inicio);

    expect(substr($html, $inicio, $fin - $inicio))->toContain('Pagar con Apple Pay o Google Pay');
});

it('sin pasarela no se ofrece el monedero, que no llevaría a ninguna parte', function (): void {
    config(['polar.access_token' => null]);

    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertDontSee('Pagar con Apple Pay o Google Pay');
});
