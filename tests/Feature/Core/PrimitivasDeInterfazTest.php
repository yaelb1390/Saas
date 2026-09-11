<?php

declare(strict_types=1);

/*
 * QUE NO VUELVA A HABER CUATRO PESTAÑAS.
 *
 * Las pestañas estaban escritas cuatro veces con tres tamaños distintos —0,3 / 0,35 / 0,5 rem de
 * relleno para el mismo control—. Y dos de esas variantes se añadieron el mismo día que se unificaron
 * las otras: así es exactamente como se acumula esta deuda. Cada pantalla nueva se escribe la suya
 * porque no sabe que hay una a la que acudir, y cuando alguien se da cuenta ya hay cuatro.
 *
 * Este test no comprueba que se vea bien: comprueba que nadie vuelva a inventarse otra. Es el mismo
 * guardián que sujeta los interruptores decorativos del terminal, y sirve para lo mismo.
 */

it('nadie se inventa otra pestaña', function (): void {
    /*
     * Las retiradas. `wa-pestana` NO está aquí a propósito: no es un duplicado descuidado sino una
     * variante de módulo, en su propia hoja, que además se activa con `aria-selected` —mejor que la
     * canónica, tanto que la canónica se lo copió—. Retirarla sería empeorar algo que funciona.
     */
    $retiradas = ['gasto-vista', 'gasto-forma'];

    $vistas = [];
    $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

    foreach ($iterador as $fichero) {
        if ($fichero->isFile() && str_ends_with($fichero->getFilename(), '.blade.php')) {
            $vistas[$fichero->getPathname()] = (string) file_get_contents($fichero->getPathname());
        }
    }

    expect($vistas)->not->toBeEmpty();

    $culpables = [];

    foreach ($vistas as $ruta => $contenido) {
        foreach ($retiradas as $clase) {
            if (str_contains($contenido, $clase)) {
                $culpables[] = basename($ruta).' usa '.$clase;
            }
        }
    }

    expect($culpables)->toBe([], 'Pestañas retiradas que volvieron: '.implode(', ', $culpables));
});

/*
 * Y QUE LAS PRIMITIVAS SIGAN EXISTIENDO.
 *
 * Si alguien las borra por «limpiar CSS sin usar», las pantallas que dependen de ellas se quedan sin
 * estilo y no falla nada: solo se ve mal, que es la clase de rotura que nadie nota hasta que la ve un
 * cliente.
 */
it('las primitivas de interfaz siguen en su sitio', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    foreach (['.bmos-pestanas', '.bmos-pestana', '.bmos-tip', '.bmos-esqueleto', '.bmos-progreso'] as $primitiva) {
        expect($css)->toContain($primitiva.' {');
    }

    // La pestaña activa se pinta por clase Y por atributo: colgarla de `aria-selected` es lo que
    // impide que lo que se ve y lo que anuncia un lector de pantalla se separen.
    expect($css)->toContain(".bmos-pestana[aria-selected='true']");

    // Y el esqueleto respeta a quien pidió menos movimiento.
    expect($css)->toContain('prefers-reduced-motion');
});
