<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Quotes\Services\QuoteDelivery;
use App\Modules\Quotes\Services\QuoteService;
use App\Modules\WhatsApp\Gateways\WhatsAppGateway;
use App\Modules\WhatsApp\Jobs\SendWhatsAppMessage;
use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

/*
 * Hacerle llegar la cotización al cliente.
 *
 * Dos cosas que vigilar, y las dos pueden fallar sin ruido:
 *
 * 1. Que se mande LO QUE SE DICE que se manda. Prometer un PDF y que llegue un enlace suelto —o
 *    nada— deja al vendedor esperando una respuesta a un mensaje que nunca salió.
 * 2. Que el enlace no sea una puerta abierta. Un chat se reenvía, y con él la dirección: si no
 *    caducara ni estuviera firmada, cualquiera vería lo que se le ofertó a otro.
 */

uses(RefreshDatabase::class);

/** Un gateway de mentira que apunta lo que se le manda, sin tocar la red. */
function gatewayDePrueba(bool $adjunta): object
{
    return new class($adjunta) implements WhatsAppGateway
    {
        /** @var array<int, array<string, string>> */
        public array $enviados = [];

        public function __construct(private readonly bool $adjunta) {}

        public function sendText(string $phone, string $body): array
        {
            $this->enviados[] = ['tipo' => 'texto', 'phone' => $phone, 'body' => $body];

            return ['external_id' => 'txt-1', 'status' => 'sent'];
        }

        public function puedeEnviarDocumentos(): bool
        {
            return $this->adjunta;
        }

        public function sendDocument(string $phone, string $url, string $fileName, string $caption = ''): array
        {
            $this->enviados[] = ['tipo' => 'documento', 'phone' => $phone, 'url' => $url,
                'file' => $fileName, 'caption' => $caption];

            return ['external_id' => 'doc-1', 'status' => 'sent'];
        }
    };
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ferretería'));
    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'dueno@ferre.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);

    $producto = Product::create(['sku' => 'CEM', 'name' => 'Cemento', 'price' => '450']);

    $this->quote = app(QuoteService::class)->crear(
        [['product_id' => $producto->id, 'quantity' => '2', 'unit_price' => '450']],
        ['customer_name' => 'Juan', 'customer_phone' => '18095551234'],
    );
});

it('con un proveedor que adjunta, manda el PDF', function (): void {
    $gateway = gatewayDePrueba(adjunta: true);
    $this->app->instance(WhatsAppGateway::class, $gateway);

    $comoFue = app(QuoteDelivery::class)->enviar($this->quote);

    // El trabajo se encola; se ejecuta aquí para ver qué llega de verdad al proveedor.
    WaMessage::query()->get()->each(fn (WaMessage $m) => (new SendWhatsAppMessage($m))
        ->handle(app(CurrentCompany::class)));

    expect($comoFue)->toContain('PDF')
        ->and($gateway->enviados[0]['tipo'])->toBe('documento')
        ->and($gateway->enviados[0]['file'])->toContain($this->quote->code)
        // La dirección tiene que ser la del PDF y venir FIRMADA: el servidor del proveedor la abre
        // por su cuenta, sin sesión ni cookies de nadie.
        ->and($gateway->enviados[0]['url'])->toContain('/pdf')
        ->and($gateway->enviados[0]['url'])->toContain('signature=');
});

it('con un proveedor que no adjunta, manda el enlace y lo dice', function (): void {
    /*
     * No es un caso raro: por la vía oficial de WhatsApp no está confirmado que se puedan mandar
     * archivos, así que se cae al enlace. Lo importante es que el mensaje que ve el vendedor diga
     * cuál de las dos cosas pasó.
     */
    $gateway = gatewayDePrueba(adjunta: false);
    $this->app->instance(WhatsAppGateway::class, $gateway);

    $comoFue = app(QuoteDelivery::class)->enviar($this->quote);

    WaMessage::query()->get()->each(fn (WaMessage $m) => (new SendWhatsAppMessage($m))
        ->handle(app(CurrentCompany::class)));

    expect($comoFue)->toContain('enlace')
        ->and($gateway->enviados[0]['tipo'])->toBe('texto')
        ->and($gateway->enviados[0]['body'])->toContain($this->quote->code);
});

it('sin teléfono no dice que se envió', function (): void {
    // «Enviada» sin haber enviado nada es la mentira más cara de esta pantalla: el vendedor se queda
    // esperando una respuesta que nunca va a llegar.
    $this->app->instance(WhatsAppGateway::class, gatewayDePrueba(adjunta: true));
    $this->quote->forceFill(['customer_phone' => null])->save();

    expect(fn () => app(QuoteDelivery::class)->enviar($this->quote->refresh()))
        ->toThrow(RuntimeException::class, 'teléfono');
});

it('el mensaje lleva el importe y la validez, no solo el enlace', function (): void {
    // Mucha gente decide con lo que ve en la notificación y no llega a abrir nada.
    $delivery = app(QuoteDelivery::class);

    $texto = $delivery->mensaje($this->quote, $delivery->enlace($this->quote));

    expect($texto)->toContain('900.00')
        ->and($texto)->toContain($this->quote->valid_until->format('d/m/Y'));
});

it('la página del cliente se abre con el enlace firmado y no sin él', function (): void {
    $firmado = app(QuoteDelivery::class)->enlace($this->quote);

    // Con firma: entra y ve su cotización.
    $this->get($firmado)->assertOk()->assertSee($this->quote->code);

    // Sin firma: no.
    $this->get(route('quotes.public', $this->quote))->assertForbidden();
});

it('un enlace caducado deja de abrir', function (): void {
    // Un chat se reenvía y sigue existiendo meses después. Una dirección eterna sería una puerta
    // abierta a lo que se le ofertó a otra persona.
    $caducado = URL::temporarySignedRoute('quotes.public', now()->subMinute(), ['quote' => $this->quote->id]);

    $this->get($caducado)->assertForbidden();
});

it('cambiar el id a mano no abre la cotización de otro', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'La de al lado'));
    app(CurrentCompany::class)->set($otra->id);
    $producto = Product::create(['sku' => 'X', 'name' => 'Otra cosa', 'price' => '10']);
    $ajena = app(QuoteService::class)->crear(
        [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '10']],
        ['customer_name' => 'Ajeno'],
    );
    app(CurrentCompany::class)->set($this->company->id);

    // La firma cubre el id: cambiarlo la invalida y el middleware corta antes del controlador.
    $manipulado = str_replace(
        '/cotizacion/'.$this->quote->id,
        '/cotizacion/'.$ajena->id,
        app(QuoteDelivery::class)->enlace($this->quote),
    );

    $this->get($manipulado)->assertForbidden();
});

it('el PDF se genera con sus líneas y su total', function (): void {
    $respuesta = $this->actingAs($this->owner)->get(route('panel.quotes.pdf', $this->quote));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        // Un PDF de dos líneas pesa unos kilobytes; uno de 0 bytes sería un fallo silencioso.
        // getContent() y no streamedContent(): dompdf devuelve una respuesta normal con el
        // archivo dentro, no una en streaming.
        ->and(strlen((string) $respuesta->getContent()))->toBeGreaterThan(1000)
        // Y que sea un PDF de verdad: los cuatro primeros bytes de todo PDF son «%PDF».
        ->and(substr((string) $respuesta->getContent(), 0, 4))->toBe('%PDF');
});

it('las cotizaciones de otra empresa no se ven en el listado', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Vecina'));
    app(CurrentCompany::class)->set($otra->id);
    $producto = Product::create(['sku' => 'Y', 'name' => 'Suyo', 'price' => '99']);
    app(QuoteService::class)->crear(
        [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '99']],
        ['customer_name' => 'Cliente de la vecina'],
    );

    app(CurrentCompany::class)->set($this->company->id);

    expect(Quote::query()->count())->toBe(1)
        ->and(Quote::query()->first()->customer_name)->toBe('Juan');
});
