<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Metrics;

use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * ¿A qué módulo pertenece esta ruta? Para poder desglosar «Rendimiento» por módulo, no solo por
 * endpoint suelto (¿lo lento es el POS o es Inventario?).
 *
 * Tres formas de averiguarlo, en orden de confianza, y todas cruzadas contra `ModuleRegistry` —una
 * corazonada que no es un módulo de verdad («admin», «api») no cuenta, cae al último recurso—:
 *
 *  1. El middleware `module:X` de la ruta: es la declaración explícita de a quién pertenece.
 *  2. El namespace del controlador (`App\Modules\{X}\...`): funciona sin que nadie declare nada.
 *  3. El primer segmento de la URI (`/pos/...`): último recurso, para rutas sin controlador de clase
 *     ni middleware de módulo (closures, rutas de plataforma).
 *
 * Sin ninguna de las tres, `'app'`: el núcleo compartido (login, dashboard, plataforma).
 *
 * Memoizado por ruta (nombre o uri): se le pregunta lo mismo en cada petición que pasa por esa ruta,
 * y resolverla cuesta recorrer middleware y reflexionar el controlador.
 */
final class ModuleResolver
{
    /** @var array<string, string> */
    private array $memo = [];

    public function resolver(?Route $ruta): string
    {
        if ($ruta === null) {
            return 'app';
        }

        $clave = $ruta->getName() ?? $ruta->uri();

        return $this->memo[$clave] ??= $this->calcular($ruta);
    }

    private function calcular(Route $ruta): string
    {
        $porMiddleware = $this->deMiddleware($ruta);

        if ($porMiddleware !== null) {
            return $porMiddleware;
        }

        $porControlador = $this->deControlador($ruta);

        if ($porControlador !== null) {
            return $porControlador;
        }

        $porUri = $this->dePrimerSegmento($ruta);

        return $porUri ?? 'app';
    }

    private function deMiddleware(Route $ruta): ?string
    {
        foreach ($ruta->gatherMiddleware() as $middleware) {
            if (! str_starts_with($middleware, 'module:')) {
                continue;
            }

            // «module:pos,quick_pos»: el primero declarado basta para clasificar la ruta.
            $primero = Str::before(Str::after($middleware, 'module:'), ',');

            if (ModuleRegistry::exists($primero)) {
                return $primero;
            }
        }

        return null;
    }

    private function deControlador(Route $ruta): ?string
    {
        $accion = $ruta->getActionName();

        if (! str_contains($accion, '@') && $accion !== 'Closure') {
            return null;
        }

        if (! preg_match('/^App\\\\Modules\\\\([A-Za-z0-9]+)\\\\/', $accion, $m)) {
            return null;
        }

        $clave = strtolower($m[1]);

        return ModuleRegistry::exists($clave) ? $clave : null;
    }

    private function dePrimerSegmento(Route $ruta): ?string
    {
        $segmento = strtolower((string) Str::of($ruta->uri())->before('/'));

        return ModuleRegistry::exists($segmento) ? $segmento : null;
    }
}
