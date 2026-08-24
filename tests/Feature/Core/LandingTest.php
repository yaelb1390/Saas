<?php

declare(strict_types=1);

use App\Modules\Core\Models\Plan;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/*
 * La presentación del producto.
 *
 * Es la primera pantalla de quien llega desde Instagram o desde un mensaje, así que lo que se fija
 * aquí es que se pueda ver SIN cuenta —pedir la contraseña antes de contar qué es esto era el
 * problema que vino a resolver— y que lo que enseña salga de donde vive la verdad: los módulos del
 * registro y los precios de la tabla. Un texto copiado a mano se queda viejo el día que cambie un
 * precio, y entonces la página que vende el producto miente sobre él.
 */

uses(RefreshDatabase::class);

/*
 * Si la página está apagada, estas pruebas se saltan en vez de fallar.
 *
 * Se mira la RUTA y no una bandera de configuración: la ruta es exactamente lo que se apaga, así que
 * volver a registrarla devuelve las pruebas solas. Sin esto habría que acordarse de reactivarlas a
 * mano, y unas pruebas que nadie reactiva son unas pruebas que se quedan viejas en silencio.
 */
beforeEach(function (): void {
    if (! Route::has('landing')) {
        test()->markTestSkipped('La presentación del producto está apagada: /bmia no está registrada.');
    }
});

it('se puede ver sin haber iniciado sesión', function (): void {
    $this->get(route('landing'))->assertOk();
});

it('los precios salen de la tabla, no escritos a mano', function (): void {
    Plan::create([
        'name' => 'Plan de prueba', 'slug' => 'prueba-landing', 'price' => '4321.00',
        'billing_cycle' => 'monthly', 'is_active' => true,
    ]);

    $this->get(route('landing'))
        ->assertOk()
        ->assertSee('Plan de prueba')
        ->assertSee('4,321.00');
});

it('un plan apagado no se anuncia', function (): void {
    // Ofrecer un plan que ya no se vende es prometer algo que no se puede contratar.
    Plan::create([
        'name' => 'Plan retirado', 'slug' => 'retirado-landing', 'price' => '99.00',
        'billing_cycle' => 'monthly', 'is_active' => false,
    ]);

    $this->get(route('landing'))->assertOk()->assertDontSee('Plan retirado');
});

it('los módulos se describen con el mismo texto que usa el resto del sistema', function (): void {
    /*
     * Del registro y no de la vista: si mañana se añade un módulo o se reescribe su descripción,
     * esta pantalla lo recoge sin que nadie tenga que acordarse de ella.
     */
    $this->get(route('landing'))
        ->assertOk()
        ->assertSee(ModuleRegistry::description('pos'))
        ->assertSee(ModuleRegistry::description('whatsapp'));
});

it('los días de prueba que ofrece son los que de verdad da el registro', function (): void {
    // Prometer catorce días y dar quince —o al revés— es la clase de detalle que se descubre tarde.
    config(['bmos.trial.days' => 21]);

    $this->get(route('landing'))->assertOk()->assertSee('21 días gratis');
});

it('el botón de WhatsApp lleva al número del operador', function (): void {
    config(['platform.support_whatsapp' => '1809-555-1234']);

    // Se limpian los guiones: wa.me solo admite dígitos, y con ellos el enlace no abre nada.
    $this->get(route('landing'))->assertOk()->assertSee('wa.me/18095551234', false);
});
