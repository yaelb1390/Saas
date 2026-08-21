<?php

declare(strict_types=1);

use App\Modules\Social\Support\AutomationOverlap;

/*
 * Cuándo una automatización no va a llegar a responder.
 *
 * Sale de un caso real: cinco automatizaciones con las mismas seis palabras clave en la misma
 * cuenta. Un comentario lo atiende una sola, así que cuatro no disparaban nunca —una con cero
 * registros en cinco días— y la pantalla decía «espera a que alguien comente», invitando a crear
 * otra igual.
 *
 * Lo que más importa aquí NO es detectar colisiones: es NO marcar las que conviven bien. Un aviso
 * que salta siempre se ignora en dos días, y entonces no avisa de nada.
 */

/** Una automatización con lo justo para decidir si compite. */
function automatizacion(array $extra = []): array
{
    return array_merge([
        'id' => 'a1', 'name' => 'Promocion', 'accountId' => 'ig_1', 'trigger' => 'comment',
        'postId' => null, 'keywords' => ['precio', 'info'], 'isActive' => true,
        'createdAt' => '2026-08-16T23:47:38.771Z',
    ], $extra);
}

it('la más nueva queda tapada por la más antigua', function (): void {
    // El caso exacto de la cuenta real: dos de toda la cuenta con las mismas palabras.
    $tapadas = AutomationOverlap::tapadas([
        automatizacion(['id' => 'vieja', 'name' => 'Promocion Todas', 'createdAt' => '2026-08-16T23:47:38Z']),
        automatizacion(['id' => 'nueva', 'name' => 'Promocion', 'createdAt' => '2026-08-20T01:03:07Z']),
    ]);

    expect($tapadas)->toHaveKey('nueva')
        ->and($tapadas['nueva']['nombre'])->toBe('Promocion Todas')
        // Y la que sí responde NO se marca: si no, el aviso saldría en las dos y no diría nada.
        ->and($tapadas)->not->toHaveKey('vieja');
});

it('con palabras distintas no se tapan', function (): void {
    // El contraste que sostiene todo lo demás: sin él, «marcar colisiones» podría estar marcándolo
    // todo y el test de arriba pasaría igual.
    $tapadas = AutomationOverlap::tapadas([
        automatizacion(['id' => 'a', 'keywords' => ['precio'], 'createdAt' => '2026-08-16T00:00:00Z']),
        automatizacion(['id' => 'b', 'keywords' => ['delivery'], 'createdAt' => '2026-08-20T00:00:00Z']),
    ]);

    expect($tapadas)->toBe([]);
});

it('una de una publicación y otra de toda la cuenta conviven', function (): void {
    /*
     * NO es una colisión, y marcarla sería mentir sobre una configuración correcta: la
     * especificación dice que «per-post automations take priority on their post», así que cada una
     * manda en su terreno.
     */
    $tapadas = AutomationOverlap::tapadas([
        automatizacion(['id' => 'todas', 'postId' => null, 'createdAt' => '2026-08-16T00:00:00Z']),
        automatizacion(['id' => 'de_una_foto', 'postId' => '17900000000000000', 'createdAt' => '2026-08-20T00:00:00Z']),
    ]);

    expect($tapadas)->toBe([]);
});

it('pero dos en la MISMA publicación sí', function (): void {
    $tapadas = AutomationOverlap::tapadas([
        automatizacion(['id' => 'a', 'postId' => '179', 'createdAt' => '2026-08-16T00:00:00Z']),
        automatizacion(['id' => 'b', 'postId' => '179', 'createdAt' => '2026-08-20T00:00:00Z']),
    ]);

    expect($tapadas)->toHaveKey('b')->and($tapadas)->not->toHaveKey('a');
});

it('cuentas distintas no compiten', function (): void {
    // Dos negocios, o dos redes del mismo negocio: no comparten ni un comentario.
    $tapadas = AutomationOverlap::tapadas([
        automatizacion(['id' => 'a', 'accountId' => 'ig_1', 'createdAt' => '2026-08-16T00:00:00Z']),
        automatizacion(['id' => 'b', 'accountId' => 'fb_9', 'createdAt' => '2026-08-20T00:00:00Z']),
    ]);

    expect($tapadas)->toBe([]);
});

it('un comentario y una historia no se pisan', function (): void {
    $tapadas = AutomationOverlap::tapadas([
        automatizacion(['id' => 'a', 'trigger' => 'comment', 'createdAt' => '2026-08-16T00:00:00Z']),
        automatizacion(['id' => 'b', 'trigger' => 'story_reply', 'createdAt' => '2026-08-20T00:00:00Z']),
    ]);

    expect($tapadas)->toBe([]);
});

it('una apagada ni tapa ni queda tapada', function (): void {
    // No consume ningún comentario, así que no le quita el turno a nadie; y decirle a una apagada
    // que está tapada es ruido sobre algo que ya no responde.
    $tapadas = AutomationOverlap::tapadas([
        automatizacion(['id' => 'apagada', 'isActive' => false, 'createdAt' => '2026-08-16T00:00:00Z']),
        automatizacion(['id' => 'encendida', 'createdAt' => '2026-08-20T00:00:00Z']),
    ]);

    expect($tapadas)->toBe([]);
});

it('compara sin importar mayúsculas ni espacios', function (): void {
    // «Precio» y «precio » son la misma palabra para quien comenta.
    $tapadas = AutomationOverlap::tapadas([
        automatizacion(['id' => 'a', 'keywords' => ['Precio'], 'createdAt' => '2026-08-16T00:00:00Z']),
        automatizacion(['id' => 'b', 'keywords' => ['  precio '], 'createdAt' => '2026-08-20T00:00:00Z']),
    ]);

    expect($tapadas)->toHaveKey('b');
});
