<?php

declare(strict_types=1);

use App\Modules\Delivery\Support\ComoLlegar;

/*
 * LOS ENLACES DE NAVEGACIÓN, SIN BASE DE DATOS.
 *
 * Son cadenas, y de ellas depende que el repartidor acabe en la puerta del cliente o en otro sitio.
 * Un carácter mal codificado no da error en ningún lado: abre el mapa, enseña un pin, y el pin está
 * donde no es. De ahí que estos tests miren la URL entera y no «que devuelva algo».
 */

// ------------------------------------------------------------------ Sin punto: manda la dirección

it('sin coordenadas navega por la direccion escrita', function (): void {
    $ir = new ComoLlegar(null, null, 'Calle Duarte 15');

    expect($ir->tienePunto())->toBeFalse()
        ->and($ir->waze())->toBe('https://waze.com/ul?q=Calle%20Duarte%2015&navigate=yes')
        ->and($ir->googleMaps())->toBe('https://www.google.com/maps/dir/?api=1&destination=Calle%20Duarte%2015');
});

/*
 * EL CASO QUE MOTIVÓ TODO ESTO: «Juana #6».
 *
 * Sin codificar, la almohadilla convierte el resto de la dirección en el ancla de la URL y el mapa
 * recibe «Juana » a secas. El repartidor vería un destino plausible pero equivocado, que es peor que
 * no ver ninguno.
 */
it('codifica una direccion con almohadilla en vez de cortarla', function (): void {
    $ir = new ComoLlegar(null, null, 'Juana #6');

    expect($ir->waze())->toContain('%236')
        ->and($ir->waze())->not->toContain('#')
        ->and($ir->googleMaps())->toContain('Juana%20%236');
});

it('codifica los acentos y las enes', function (): void {
    $ir = new ComoLlegar(null, null, 'Callejón Peña');

    expect($ir->waze())->not->toContain('ó')
        ->and($ir->waze())->toContain('Callej%C3%B3n%20Pe%C3%B1a');
});

// ------------------------------------------------------------------ Con punto: manda la coordenada

it('con coordenadas navega al punto exacto', function (): void {
    $ir = new ComoLlegar('18.4861', '-69.9312', 'Juana #6');

    expect($ir->tienePunto())->toBeTrue()
        ->and($ir->waze())->toBe('https://waze.com/ul?ll=18.4861000,-69.9312000&navigate=yes')
        ->and($ir->googleMaps())->toBe('https://www.google.com/maps/dir/?api=1&destination=18.4861000,-69.9312000');
});

/*
 * La coma del par de coordenadas NO se codifica: es separador, no contenido. Y el número va como
 * cadena hasta el final —en coma flotante, 18.4861 puede escribirse «18.486099999999997» y ensuciar
 * el enlace sin ganar precisión ninguna.
 */
it('no codifica la coma que separa las coordenadas', function (): void {
    $ir = new ComoLlegar('18.4861', '-69.9312', 'Da igual');

    expect($ir->googleMaps())->not->toContain('%2C')
        ->and($ir->googleMaps())->toContain('18.4861000,-69.9312000');
});

it('normaliza siempre a siete decimales', function (): void {
    expect((new ComoLlegar('18.5', '-69.9', 'x'))->waze())->toContain('ll=18.5000000,-69.9000000');

    // Y recorta lo que sobra en vez de arrastrar veinte decimales de un GPS hablador.
    expect((new ComoLlegar('18.48610009999', '-69.93120001', 'x'))->waze())
        ->toContain('ll=18.4861001,-69.9312000');
});

/*
 * Media coordenada no es una coordenada. Si un lado llegara vacío se compondría un enlace con
 * «18.4861,0» —el meridiano de Greenwich— y el repartidor saldría hacia el Atlántico.
 */
it('media coordenada no cuenta como punto', function (): void {
    expect((new ComoLlegar('18.4861', null, 'Calle Duarte 15'))->tienePunto())->toBeFalse()
        ->and((new ComoLlegar(null, '-69.9312', 'Calle Duarte 15'))->tienePunto())->toBeFalse()
        ->and((new ComoLlegar('18.4861', null, 'Calle Duarte 15'))->waze())->toContain('q=Calle');
});
