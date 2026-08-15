<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;

/*
 * Las confirmaciones se piden con el diálogo del panel, no con el del navegador.
 *
 * Este test existe porque el fallo que vigila NO se nota. Escribir
 * `<form onsubmit="return confirm('¿Eliminar?')">` funciona: el borrado se hace, ningún test se
 * pone rojo, la pantalla responde 200. Lo único que pasa es que aparece la ventana gris del sistema
 * —con el dominio como título y botones «Aceptar/Cancelar»— en medio de un panel que por lo demás
 * está cuidado. Es el patrón que había en veinte sitios y el que volvería solo, copiando la fila de
 * al lado, si nadie lo impide.
 *
 * Y hay un motivo que va más allá del aspecto: el `confirm()` nativo trata igual archivar un cliente
 * que destruir un plan. El componente obliga a elegir tono y a escribir qué pasa después, así que
 * prohibirlo aquí es lo que mantiene esa distinción viva.
 */

/**
 * Todas las plantillas Blade del proyecto.
 *
 * @return list<string>
 */
function plantillas(): array
{
    return collect(File::allFiles(resource_path('views')))
        ->filter(fn ($archivo): bool => str_ends_with($archivo->getFilename(), '.blade.php'))
        ->map(fn ($archivo): string => $archivo->getPathname())
        ->values()
        ->all();
}

it('ninguna vista usa el confirm del navegador', function (): void {
    // Se busca `confirm(` como llamada suelta. `window.confirmarAccion(` no encaja: lleva otro
    // nombre. La negación de `\w` delante evita que «confirmarAccion(» cuente como coincidencia.
    $culpables = [];

    foreach (plantillas() as $ruta) {
        $contenido = File::get($ruta);

        if (preg_match('/(?<![\w.])confirm\s*\(/', $contenido) === 1) {
            $culpables[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $ruta);
        }
    }

    expect($culpables)->toBe([], sprintf(
        "Estas vistas piden confirmación con la ventana del navegador:\n- %s\n".
        'Usa <x-panel.confirm-action> (formularios) o window.confirmarAccion() (Alpine).',
        implode("\n- ", $culpables),
    ));
});

it('el componente pinta el formulario en el servidor, con su token y su método', function (): void {
    // El JavaScript solo envía el formulario; NUNCA fabrica el CSRF ni el `_method`. Si alguien
    // moviera esa responsabilidad al navegador, el borrado seguiría funcionando en local y fallaría
    // con 419 en cuanto cambiara la sesión.
    $html = (string) view('components.panel.confirm-action', [
        'action' => 'https://ejemplo.test/clientes/7',
        'method' => 'DELETE',
        'title' => '¿Eliminar a «Ana»?',
        'slot' => new HtmlString('Eliminar'),
        'attributes' => new ComponentAttributeBag,
    ])->render();

    expect($html)
        ->toContain('name="_token"')
        ->toContain('value="DELETE"')
        ->toContain('action="https://ejemplo.test/clientes/7"')
        ->toContain('window.confirmarAccion(');
});

it('el botón no envía nada por sí solo', function (): void {
    // `type="button"`, no `submit`: si fuera un submit, el navegador mandaría el formulario ANTES de
    // que el diálogo llegara a abrirse en cuanto el botón cayera dentro de otro <form>.
    $html = (string) view('components.panel.confirm-action', [
        'action' => 'https://ejemplo.test/planes/3',
        'title' => '¿Eliminar el plan?',
        'slot' => new HtmlString('Eliminar'),
        'attributes' => new ComponentAttributeBag,
    ])->render();

    expect($html)->toContain('<button type="button"');
});

it('las funciones del diálogo existen en el paquete de JavaScript', function (): void {
    // Las vistas llaman a `window.confirmarAccion` desde un `onclick`. Si el nombre cambiara en
    // app.js, el botón dejaría de hacer NADA: sin error visible, sin excepción, sin registro. Es
    // exactamente el fallo que ningún test de HTTP puede ver.
    $js = File::get(resource_path('js/app.js'));

    $ausentes = array_values(array_filter(
        ['confirmarAccion', 'confirmarBorrarProductos', 'confirmarAnularVentas', 'confirmarEliminarEmpresa'],
        fn (string $funcion): bool => ! str_contains($js, "window.{$funcion} ="),
    ));

    expect($ausentes)->toBe([], sprintf(
        'Faltan en resources/js/app.js: %s. Las vistas que las llaman se quedarían mudas.',
        implode(', ', $ausentes),
    ));
});
