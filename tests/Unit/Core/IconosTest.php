<?php

declare(strict_types=1);

use App\Modules\Core\Support\Icons;

/*
 * Los iconos del panel.
 *
 * Lo que se vigila aquí es lo único que puede romperse en silencio: pedir un icono que no está. Como
 * <x-icono> no pinta nada cuando el nombre no existe —a propósito, para que un dibujo que falta no
 * tumbe una pantalla entera—, un nombre mal escrito no da error: deja un hueco que nadie nota hasta
 * que alguien pregunta por qué a esa tarjeta le falta el icono.
 *
 * Así que el test lee los nombres que las vistas piden DE VERDAD, sin que haya que mantener una
 * lista aparte —que se quedaría vieja el primer día—, y comprueba que cada uno existe.
 *
 * Es un test de unidad: no arranca la aplicación, solo lee ficheros. Por eso las rutas se calculan
 * desde __DIR__ y no con resource_path(), que aquí no está disponible.
 */

/** La raíz del proyecto: tests/Unit/Core → tres niveles arriba. */
function raizDelProyecto(): string
{
    return dirname(__DIR__, 3);
}

/**
 * Todos los iconos que las vistas piden por su nombre literal.
 *
 * @return list<array{0: string, 1: string}> nombre, y fichero donde se pide
 */
function iconosPedidosEnLasVistas(): array
{
    $pedidos = [];
    // SKIP_DOTS no es opcional: sin él el iterador entra en «.» y se recorre a sí mismo sin fin,
    // y el proceso muere sin imprimir una sola línea, que es la peor forma de fallar.
    $vistas = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            raizDelProyecto().'/resources/views',
            RecursiveDirectoryIterator::SKIP_DOTS
        )
    );

    foreach ($vistas as $vista) {
        if (! str_ends_with((string) $vista, '.blade.php')) {
            continue;
        }

        $texto = (string) file_get_contents((string) $vista);

        /*
         * Dos formas de pedir un icono, y hacen falta las dos.
         *
         * La primera es el atributo directo: <x-icono name="cash">. La segunda es dentro de un array
         * PHP —'icono' => 'cash' en las tarjetas de <x-panel.metricas>—, donde el nombre nunca llega a
         * escribirse como atributo y la primera no lo ve. Comprobado por mutación: con solo la
         * primera, cambiar el icono de una métrica por uno inexistente pasaba el test sin más.
         */
        preg_match_all('/<x-icono[^>]*\sname="([^"$]+)"/', $texto, $atributos);
        preg_match_all("/'icono'\s*=>\s*'([a-z-]+)'/", $texto, $enArrays);

        foreach ([...$atributos[1], ...$enArrays[1]] as $nombre) {
            $pedidos[] = [$nombre, basename((string) $vista)];
        }
    }

    return $pedidos;
}

it('las vistas solo piden iconos que existen', function (): void {
    $pedidos = iconosPedidosEnLasVistas();

    // Si esto se queda en cero es que el patrón dejó de casar y el test ya no vigila nada.
    expect($pedidos)->not->toBeEmpty();

    foreach ($pedidos as [$nombre, $vista]) {
        expect(Icons::has($nombre))->toBeTrue("La vista {$vista} pide el icono «{$nombre}», que no existe.");
    }
});

it('cada entrada del menú tiene su dibujo', function (): void {
    /*
     * El menú pinta <x-icono :name="$icon">, con el nombre por variable, así que el test de arriba no
     * lo ve. Los nombres están en el array $nav del propio layout, en la tercera posición de cada
     * entrada: [ruta, etiqueta, icono, permiso, módulo].
     */
    $layout = (string) file_get_contents(
        raizDelProyecto().'/resources/views/components/layouts/admin.blade.php'
    );

    preg_match_all("/\['[a-z0-9._-]+', '[^']+', '([a-z-]+)',/", $layout, $m);

    // Veintitantas entradas: si salen cuatro es que el array cambió de forma y esto dejó de mirar.
    expect($m[1])->toHaveCount(count(array_unique($m[1])) > 0 ? count($m[1]) : 0)
        ->and(count($m[1]))->toBeGreaterThan(15);

    foreach (array_unique($m[1]) as $icono) {
        expect(Icons::has($icono))->toBeTrue("El menú pide el icono «{$icono}», que no existe.");
    }
});

it('un nombre que no existe no revienta, devuelve vacío', function (): void {
    // Es lo que permite que <x-icono> no pinte nada: un dibujo que falta no debe tumbar la página.
    expect(Icons::path('esto-no-existe'))->toBe('')
        ->and(Icons::has('esto-no-existe'))->toBeFalse();
});

it('ningún icono se quedó sin trazo', function (): void {
    // Una entrada vacía es igual de invisible que un nombre mal escrito, y más difícil de encontrar.
    foreach (Icons::names() as $nombre) {
        expect(Icons::path($nombre))->not->toBe('', "El icono «{$nombre}» está declarado pero vacío.");
    }
});
