<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\WhatsApp\Enums\MessageDirection;
use App\Modules\WhatsApp\Models\WaBotSetting;
use App\Modules\WhatsApp\Models\WaConversation;
use App\Modules\WhatsApp\Models\WaMessage;
use App\Modules\WhatsApp\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Dos cosas que tocan datos de personas: darlas de alta y borrarlas.
 *
 * Las dos se equivocan hacia el mismo lado a propósito. Ante la duda NO se crea la ficha —un CRM
 * lleno de contactos imposibles de llamar no vale nada— y ante la duda NO se borra el historial,
 * porque eso no tiene vuelta atrás.
 */

uses(RefreshDatabase::class);

/** Un mensaje viejo, con la fecha que se le diga. */
function mensajeConFecha(int $companyId, int $conversacionId, string $cuerpo, $cuando): WaMessage
{
    $mensaje = WaMessage::withoutGlobalScopes()->create([
        'company_id' => $companyId,
        'wa_conversation_id' => $conversacionId,
        'direction' => MessageDirection::Inbound,
        'type' => 'text',
        'body' => $cuerpo,
        'status' => 'received',
        'sent_at' => $cuando,
    ]);

    // `created_at` lo pone Eloquent: hay que forzarlo después, que es por lo que se mide la antigüedad.
    $mensaje->forceFill(['created_at' => $cuando, 'updated_at' => $cuando])->save();

    return $mensaje;
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado CRM'));
    app(CurrentCompany::class)->set($this->company->id);
});

// ------------------------------------------------------------------- Quien escribe, entra al CRM

it('quien escribe por primera vez queda dado de alta en el CRM', function (): void {
    /*
     * El hueco que se cierra: antes la conversación existía y el cliente NO. Se le podía contestar
     * pero no cotizar, ni ver qué compró la última vez, sin darlo de alta a mano número en mano.
     */
    app(WhatsAppService::class)->recordInbound('18095551234', 'buenas, tienen agua?', null, 'Ramona');

    $cliente = Customer::withoutGlobalScopes()->first();

    expect($cliente)->not->toBeNull()
        ->and($cliente->name)->toBe('Ramona')
        ->and($cliente->phone)->toBe('18095551234')
        ->and($cliente->company_id)->toBe($this->company->id);

    // Y la conversación queda atada a la ficha, que es lo que hace útil la insignia de la bandeja.
    expect(WaConversation::withoutGlobalScopes()->first()->customer_id)->toBe($cliente->id);
});

it('no duplica la ficha de un cliente que ya estaba, ni le pisa el nombre', function (): void {
    /*
     * El nombre de WhatsApp se lo pone el cliente y cambia cuando le apetece. El del CRM lo escribió
     * el negocio, a veces con un apodo o el nombre del colmado. Que el primero mande sobre el segundo
     * le reescribiría la agenda al dueño cada vez que a alguien le da por cambiarse el perfil.
     */
    $original = Customer::create([
        'company_id' => $this->company->id,
        'name' => 'Doña Ramona (la del colmado)',
        'phone' => '18095551234',
        'is_active' => true,
    ]);

    app(WhatsAppService::class)->recordInbound('18095551234', 'hola', null, 'Ramoncita 💅');

    expect(Customer::withoutGlobalScopes()->count())->toBe(1)
        ->and($original->fresh()->name)->toBe('Doña Ramona (la del colmado)');
});

it('un identificador que no es un teléfono NO crea ficha', function (): void {
    /*
     * Por la vía oficial de Meta el remitente puede venir como BSUID: un identificador interno, no un
     * número. Una ficha con eso en el campo del teléfono no sirve para llamar a nadie; solo ensucia
     * el CRM con contactos que el dueño no puede usar y que tendrá que borrar uno a uno.
     */
    app(WhatsAppService::class)->recordInbound('BSUID_7f3a9c2e', 'hola', null, 'Alguien');

    expect(Customer::withoutGlobalScopes()->count())->toBe(0);

    // Pero el MENSAJE sí se guarda: no poder ficharle no es motivo para perder lo que dijo.
    expect(WaMessage::withoutGlobalScopes()->count())->toBe(1);
});

it('sin el módulo de CRM contratado no se le llena la tabla a nadie', function (): void {
    /*
     * Guardarle datos personales de sus clientes a una empresa que no puede abrir esa pantalla es
     * guardárselos sin darle forma de verlos ni de borrarlos. Si un día contrata el CRM, la ficha se
     * crea entonces; mientras tanto la conversación funciona igual.
     */
    $empresa = $this->company;
    $empresa->forceFill(['modules' => ['pos']])->save();
    app(CurrentCompany::class)->forget();
    app(CurrentCompany::class)->set($empresa->id);

    app(WhatsAppService::class)->recordInbound('18095559999', 'hola', null, 'Nuevo');

    expect(Customer::withoutGlobalScopes()->count())->toBe(0)
        ->and(WaMessage::withoutGlobalScopes()->count())->toBe(1);
})->skip(fn (): bool => ! method_exists(Company::class, 'hasModule'), 'Sin módulos no aplica');

// ------------------------------------------------------------------- El borrado

it('con la retención en cero NO se borra absolutamente nada', function (): void {
    /*
     * EL TEST QUE MÁS IMPORTA DE LOS TRES.
     *
     * Cero es el valor de fábrica, así que esto es lo que le pasa a todo negocio que no ha tocado
     * nada. Un valor por omisión puede dejar una función apagada; lo que no puede es destruirle a
     * alguien el historial de sus clientes sin que lo haya pedido, porque eso no se deshace.
     */
    $conversacion = conversacionVieja($this->company->id);
    mensajeConFecha($this->company->id, $conversacion->id, 'de hace tres años', now()->subYears(3));

    WaBotSetting::paraEmpresa($this->company->id)->forceFill(['retention_days' => 0])->save();

    $this->artisan('conversaciones:purgar')->assertExitCode(0);

    expect(WaMessage::withoutGlobalScopes()->count())->toBe(1);
});

it('borra lo viejo y respeta lo que entra dentro del plazo', function (): void {
    $conversacion = conversacionVieja($this->company->id);

    mensajeConFecha($this->company->id, $conversacion->id, 'viejísimo', now()->subDays(120));
    mensajeConFecha($this->company->id, $conversacion->id, 'justo al filo', now()->subDays(89));
    mensajeConFecha($this->company->id, $conversacion->id, 'de ayer', now()->subDay());

    WaBotSetting::paraEmpresa($this->company->id)->forceFill(['retention_days' => 90])->save();

    $this->artisan('conversaciones:purgar')->assertExitCode(0);

    $quedan = WaMessage::withoutGlobalScopes()->pluck('body')->all();

    expect($quedan)->toHaveCount(2)
        ->and($quedan)->toContain('justo al filo')
        ->and($quedan)->toContain('de ayer')
        ->and($quedan)->not->toContain('viejísimo');
});

it('la retención de una empresa NO borra los mensajes de otra', function (): void {
    /*
     * El fallo clásico de un comando de consola: no hay empresa activa, así que el aislamiento
     * automático no filtra nada y un `delete()` se lleva la tabla entera. Aquí eso significaría
     * borrarle el historial a un negocio porque el de al lado configuró noventa días.
     */
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ferretería Ajena'));

    $mia = conversacionVieja($this->company->id);
    mensajeConFecha($this->company->id, $mia->id, 'de la mía', now()->subDays(200));

    $suya = conversacionVieja($otra->id, '18095558888');
    mensajeConFecha($otra->id, $suya->id, 'de la otra', now()->subDays(200));

    // Solo la primera empresa pide que se le borre.
    WaBotSetting::paraEmpresa($this->company->id)->forceFill(['retention_days' => 30])->save();
    WaBotSetting::paraEmpresa($otra->id)->forceFill(['retention_days' => 0])->save();

    $this->artisan('conversaciones:purgar')->assertExitCode(0);

    $quedan = WaMessage::withoutGlobalScopes()->pluck('body')->all();

    expect($quedan)->toBe(['de la otra']);
});

it('un número de días negativo no borra la conversación entera', function (): void {
    /*
     * Restarle días negativos a hoy da una fecha EN EL FUTURO, y «todo lo anterior a mañana» es todo,
     * incluido el mensaje que entró hace un minuto. Es la clase de valor que llega por una migración
     * mal hecha o un formulario sin validar, y el daño sería total e irreversible.
     */
    $conversacion = conversacionVieja($this->company->id);
    mensajeConFecha($this->company->id, $conversacion->id, 'recién llegado', now());

    WaBotSetting::paraEmpresa($this->company->id)->forceFill(['retention_days' => -5])->save();

    $this->artisan('conversaciones:purgar')->assertExitCode(0);

    expect(WaMessage::withoutGlobalScopes()->count())->toBe(1);
});

it('se lleva también la conversación que se quedó sin un solo mensaje', function (): void {
    /*
     * Si no, la bandeja se llena de hilos vacíos: el nombre y el teléfono de un cliente sin nada
     * dentro. Es lo peor de las dos opciones —se conserva el dato personal, que era justo lo que se
     * quería soltar, y encima no sirve para nada—.
     */
    $vacía = conversacionVieja($this->company->id, '18095551111');
    mensajeConFecha($this->company->id, $vacía->id, 'todo esto es viejo', now()->subDays(200));

    $viva = conversacionVieja($this->company->id, '18095552222');
    mensajeConFecha($this->company->id, $viva->id, 'viejo', now()->subDays(200));
    mensajeConFecha($this->company->id, $viva->id, 'pero sigue hablando', now());

    WaBotSetting::paraEmpresa($this->company->id)->forceFill(['retention_days' => 30])->save();

    $this->artisan('conversaciones:purgar')->assertExitCode(0);

    $telefonos = WaConversation::withoutGlobalScopes()->pluck('phone')->all();

    expect($telefonos)->toBe(['18095552222']);
});

it('simular cuenta lo que se perdería sin tocar nada', function (): void {
    // Antes de encender un borrado irreversible hay que poder ver el número. Sin esto, la única
    // manera de saber cuánto se va a llevar es dejar que se lo lleve.
    $conversacion = conversacionVieja($this->company->id);
    mensajeConFecha($this->company->id, $conversacion->id, 'viejo', now()->subDays(200));

    WaBotSetting::paraEmpresa($this->company->id)->forceFill(['retention_days' => 30])->save();

    $this->artisan('conversaciones:purgar --simular')->assertExitCode(0);

    expect(WaMessage::withoutGlobalScopes()->count())->toBe(1);
});

/** Una conversación cualquiera de una empresa. */
function conversacionVieja(int $companyId, string $phone = '18095557777'): WaConversation
{
    return WaConversation::withoutGlobalScopes()->firstOrCreate(
        ['company_id' => $companyId, 'phone' => $phone],
        ['name' => 'Alguien', 'last_message_at' => now()],
    );
}
