<?php

declare(strict_types=1);

use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Monitoring\Errors\ServiceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * El servicio de un suceso, y el redactado de su detalle.
 *
 * El «servicio» (`polar`, `evolution`, `ai`, `auth`…) es lo que permite filtrar el registro por «¿qué falla,
 * Evolution o la base de datos?». Se deduce del tipo y del mensaje, y cuando no se puede saber se deja
 * VACÍO: es mejor no decir nada que decir uno falso.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    SystemEvent::olvidarSiHayTabla();
});

it('deduce el servicio del tipo y del mensaje', function (string $tipo, string $mensaje, ?string $servicio): void {
    expect(ServiceResolver::forEvent($tipo, $mensaje))->toBe($servicio);
})->with([
    'un acceso' => ['auth.login', 'Inició sesión', 'auth'],
    'un acceso fallido' => ['auth.failed', 'Intento de acceso fallido', 'auth'],
    'una tarea programada' => ['task.run', 'Se ejecutó la tarea', 'scheduler'],
    'un correo' => ['mail.test_sent', 'Se envió un correo de prueba', 'mail'],
    'una suscripción' => ['subscription.ended', 'La suscripción terminó', 'polar'],
    'una acción del operador' => ['platform.company_suspended', 'Empresa suspendida', 'platform'],
    'un fallo de Polar' => ['integration.failed', 'Polar: no se pudo abrir el cobro', 'polar'],
    'un fallo de Zernio' => ['integration.failed', 'Zernio: la cuenta no responde', 'zernio'],
    'la bienvenida, que es de las redes' => ['integration.failed', 'La bienvenida no se pudo enviar', 'zernio'],
    'el sentimiento, que es de la IA' => ['integration.failed', 'No se pudo clasificar el sentimiento de un mensaje de WhatsApp', 'ai'],
    'el bot de WhatsApp' => ['integration.failed', 'El bot de WhatsApp no pudo contestar', 'evolution'],
    'la conexión de la línea' => ['integration.whatsapp', 'La línea de WhatsApp quedó conectada', 'evolution'],
    'un webhook de Polar rechazado' => ['webhook.rejected', 'Webhook de Polar rechazado: firma no válida', 'polar'],
    'un webhook de redes rechazado' => ['webhook.rejected', 'Aviso de redes rechazado: no corresponde a ninguna empresa', 'zernio'],
    'algo que no se sabe' => ['integration.failed', 'Falló una cosa cualquiera', null],
    'un tipo desconocido' => ['algo.raro', 'Polar', null],
]);

it('guarda el servicio deducido', function (): void {
    SystemEvent::registrar('integration.failed', 'Polar: no se pudo abrir el cobro');

    expect(SystemEvent::query()->sole()->service)->toBe('polar');
});

it('el servicio que se pasa a mano gana sobre el deducido', function (): void {
    SystemEvent::registrar('integration.failed', 'Polar: no se pudo abrir el cobro', service: 'zernio');

    expect(SystemEvent::query()->sole()->service)->toBe('zernio');
});

it('un suceso de servicio desconocido guarda el servicio vacío, no uno inventado', function (): void {
    SystemEvent::registrar('algo.raro', 'Sin pistas');

    expect(SystemEvent::query()->sole()->service)->toBeNull();
});

it('tacha por el nombre de la clave del detalle, no solo por lo que parezca una clave', function (): void {
    SystemEvent::registrar('integration.failed', 'Polar: falló', [
        'password' => 'hunter2-la-clave',
        'token' => 'no-parece-una-clave-de-nadie',
        'estado' => 500,
        'respuesta' => 'todo bien',
    ]);

    $detalle = SystemEvent::query()->sole()->context;

    expect($detalle['password'])->toBe('***')
        ->and($detalle['token'])->toBe('***')
        ->and($detalle['estado'])->toBe(500)
        ->and($detalle['respuesta'])->toBe('todo bien');
});

it('tacha una clave dentro de un texto del detalle', function (): void {
    SystemEvent::registrar('integration.failed', 'Polar: falló', [
        'respuesta' => 'Invalid key sk-proj-abcdefghijklmnopqrstuv for the request',
    ]);

    $texto = SystemEvent::query()->sole()->context['respuesta'];

    expect($texto)->toContain('***')->not->toContain('sk-proj-abcdefghijklmnopqrstuv');
});
