<?php

declare(strict_types=1);

use App\Modules\Core\Support\Tendencia;

/*
 * El cálculo de una tendencia.
 *
 * Lo que se vigila aquí no es la aritmética —dividir y multiplicar por cien no se rompe solo— sino
 * las tres decisiones que producen un número creíble y falso si se equivocan, que es mucho peor que
 * no enseñar nada: qué color merece el cambio, qué hacer cuando antes era cero, y qué hacer cuando
 * el punto de partida es negativo.
 */

it('verde y rojo significan BIEN y MAL, no arriba y abajo', function (): void {
    /*
     * La regla que sostiene todo lo demás.
     *
     * Si esto se rompe, un almacén vaciándose o una racha de accesos fallidos se pintan de verde y
     * se leen como una buena noticia. Es el fallo más caro que puede tener esta clase porque no
     * parece un fallo: la pantalla se ve perfecta.
     */
    $subenLasVentas = Tendencia::calcular(100.0, 150.0, subeEsBueno: true, detalle: '');
    $subenLosFallidos = Tendencia::calcular(100.0, 150.0, subeEsBueno: false, detalle: '');

    expect($subenLasVentas['signo'])->toBe('bueno')
        ->and($subenLosFallidos['signo'])->toBe('malo')
        // El mismo +50% en las dos: lo único que cambia es qué significa crecer.
        ->and($subenLasVentas['texto'])->toBe('+50%')
        ->and($subenLosFallidos['texto'])->toBe('+50%');
});

it('bajar es bueno cuando lo que baja es un problema', function (): void {
    $menosAvisos = Tendencia::calcular(2.0, 1.0, subeEsBueno: false, detalle: '');

    expect($menosAvisos['signo'])->toBe('bueno')
        ->and($menosAvisos['texto'])->toBe('−50%');
});

it('sin dirección declarada no pinta ningún color', function (): void {
    // «Sucesos registrados» sube: ni bueno ni malo. Darle color sería inventarse un juicio.
    expect(Tendencia::calcular(26.0, 49.0, subeEsBueno: null, detalle: '')['signo'])->toBe('neutro')
        ->and(Tendencia::calcular(49.0, 26.0, subeEsBueno: null, detalle: '')['signo'])->toBe('neutro');
});

it('de cero a algo dice «nuevo», no un porcentaje inventado', function (): void {
    /*
     * De 0 a 5 no es «+500 %» ni «+100 %»: no existe porcentaje, porque no se puede dividir entre
     * cero. Cualquier cifra que se pusiera ahí sería una invención con aspecto de medida.
     */
    $aparece = Tendencia::calcular(0.0, 5.0, subeEsBueno: false, detalle: '');

    expect($aparece['texto'])->toBe('nuevo')
        // Y sigue sabiendo que es malo: aparecer de la nada no lo vuelve neutro.
        ->and($aparece['signo'])->toBe('malo');
});

it('de la nada a la nada no enseña nada', function (): void {
    // Un «0 %» en una tarjeta que siempre estuvo vacía es ruido que ocupa el sitio de un dato.
    expect(Tendencia::calcular(0.0, 0.0, subeEsBueno: true, detalle: ''))->toBeNull();
});

it('un saldo negativo que se recupera se pinta en verde', function (): void {
    /*
     * El caso que delata si se divide por el número con signo en vez de por su valor absoluto.
     *
     * De −10.000 a +15.755 el negocio ha mejorado. Dividiendo por −10.000 el porcentaje sale
     * NEGATIVO y una recuperación de veinticinco mil pesos se pintaría de rojo.
     */
    $recuperado = Tendencia::calcular(-10_000.0, 15_755.0, subeEsBueno: true, detalle: '');

    expect($recuperado['signo'])->toBe('bueno')
        ->and($recuperado['texto'])->toStartWith('+');
});

it('un cambio insignificante es cero, no un decimal de ruido', function (): void {
    // «+0.02 %» no es una tendencia, es la cifra respirando.
    $casiIgual = Tendencia::calcular(100_000.0, 100_001.0, subeEsBueno: true, detalle: '');

    expect($casiIgual['texto'])->toBe('0%')
        ->and($casiIgual['signo'])->toBe('neutro');
});

it('escribe el porcentaje sin decimales de adorno', function (): void {
    expect(Tendencia::calcular(100.0, 200.0, true, '')['texto'])->toBe('+100%')
        ->and(Tendencia::calcular(100.0, 112.4, true, '')['texto'])->toBe('+12.4%');
});

it('lleva la explicación completa para el ratón', function (): void {
    // El porcentaje solo no dice contra qué se compara; el título sí, y es donde se resuelve la duda.
    $t = Tendencia::calcular(2.0, 1.0, false, 'Avisos: 1 en las últimas 24 h frente a 2 en las 24 anteriores.');

    expect($t['detalle'])->toContain('frente a 2');
});
