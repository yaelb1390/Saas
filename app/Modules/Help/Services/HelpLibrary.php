<?php

declare(strict_types=1);

namespace App\Modules\Help\Services;

use App\Modules\Help\Support\HelpArticle;
use Illuminate\Support\Collection;

/**
 * Lee el manual de `resources/help/` y lo mantiene en memoria durante la petición.
 *
 * No se cachea entre peticiones a propósito. Son unos treinta ficheros pequeños; leerlos cuesta menos
 * que el riesgo de que alguien despliegue un artículo corregido y el panel siga enseñando el viejo
 * hasta que caduque una caché. En Vercel, además, cada invocación arranca fría y la caché no se
 * aprovecharía.
 */
final class HelpLibrary
{
    /** @var Collection<string, HelpArticle>|null */
    private ?Collection $articulos = null;

    public function __construct(private readonly ?string $directorio = null) {}

    /**
     * Todos los artículos, indexados por slug.
     *
     * @return Collection<string, HelpArticle>
     */
    public function all(): Collection
    {
        if ($this->articulos !== null) {
            return $this->articulos;
        }

        $directorio = $this->directorio ?? resource_path('help');

        if (! is_dir($directorio)) {
            return $this->articulos = collect();
        }

        $ficheros = glob($directorio.'/*.md') ?: [];

        return $this->articulos = collect($ficheros)
            ->map(static fn (string $ruta): HelpArticle => HelpArticle::fromFile($ruta))
            ->keyBy(static fn (HelpArticle $articulo): string => $articulo->slug);
    }

    public function find(string $slug): ?HelpArticle
    {
        return $this->all()->get($slug);
    }

    /**
     * Artículos de un módulo concreto.
     *
     * @return Collection<string, HelpArticle>
     */
    public function forModule(string $module): Collection
    {
        return $this->all()->filter(
            static fn (HelpArticle $articulo): bool => $articulo->module === $module,
        );
    }
}
