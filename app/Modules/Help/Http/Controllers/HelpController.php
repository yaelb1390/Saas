<?php

declare(strict_types=1);

namespace App\Modules\Help\Http\Controllers;

use App\Modules\Help\Services\HelpAnswerer;
use App\Modules\Help\Services\HelpLibrary;
use App\Modules\Help\Services\HelpSearch;
use App\Modules\Help\Support\HelpArticle;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Pantalla de ayuda. Sin permisos ni módulo: la ayuda de uso la necesita todo el que entra, y quien
 * más la necesita es justo el cliente del plan más barato.
 *
 * Todo el filtrado por plan y permisos ocurre en `HelpSearch`, con el usuario de la petición.
 */
final class HelpController extends Controller
{
    public function index(Request $request, HelpSearch $search, HelpAnswerer $answerer): View
    {
        $pregunta = trim((string) $request->query('q', ''));
        $usuario = $request->user();

        $respuesta = $pregunta !== '' ? $answerer->answer($pregunta, $usuario) : null;

        return view('panel.help', [
            'pregunta' => $pregunta,
            'respuesta' => $respuesta,
            // El índice solo cuando no se ha preguntado nada: con una respuesta delante, una lista de
            // treinta artículos debajo estorba más que ayuda.
            'indice' => $respuesta === null ? $search->index($usuario) : collect(),
            'sugerencias' => HelpLibrary::SUGERENCIAS,
        ]);
    }

    /**
     * Un artículo concreto, al que se llega desde el índice o desde «también puede interesarte».
     */
    public function show(Request $request, string $slug, HelpLibrary $library, HelpSearch $search): View
    {
        $articulo = $library->find($slug);

        // Se comprueba contra los VISIBLES y no contra la biblioteca entera: si no, bastaría con
        // teclear el slug para leer cómo funciona un módulo que la empresa no contrató.
        $visible = $articulo !== null && $search->index($request->user())
            ->flatten()
            ->contains(static fn (HelpArticle $a): bool => $a->slug === $articulo->slug);

        abort_unless($visible, 404);

        return view('panel.help-article', [
            'articulo' => $articulo,
            'relacionados' => collect($articulo->related)
                ->map(static fn (string $s): ?HelpArticle => $library->find($s))
                ->filter()
                ->values(),
        ]);
    }
}
