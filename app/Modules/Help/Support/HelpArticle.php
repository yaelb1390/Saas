<?php

declare(strict_types=1);

namespace App\Modules\Help\Support;

use Illuminate\Support\Str;

/**
 * Un artículo del manual, ya leído y separado en cabecera y cuerpo.
 *
 * Los artículos son ficheros en `resources/help/`, no filas de una tabla: el manual documenta el
 * SISTEMA, así que cambia con el código y se versiona con él. En una tabla, cada instalación tendría
 * su copia envejeciendo por su cuenta.
 */
final readonly class HelpArticle
{
    /**
     * @param  string  $slug  nombre del fichero sin extensión; es el identificador en la URL
     * @param  string|null  $module  clave de ModuleRegistry; null = lo ve todo el mundo
     * @param  string|null  $permission  permiso necesario; null = lo ve todo el mundo
     * @param  list<string>  $keywords  cómo lo llama el CLIENTE, no cómo lo llama el manual
     * @param  list<string>  $related  slugs de artículos relacionados
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $body,
        public ?string $module = null,
        public ?string $permission = null,
        public array $keywords = [],
        public array $related = [],
        public ?string $route = null,
    ) {}

    /**
     * Lee un artículo de su fichero.
     *
     * La cabecera va entre `---` y es deliberadamente tonta —`clave: valor`, una por línea, sin
     * anidar—: así se parsea con cuatro líneas y sin depender de un parser de YAML, y no hay forma de
     * escribir una cabecera «casi válida» que se interprete de otra manera.
     */
    public static function fromFile(string $ruta): self
    {
        $contenido = (string) file_get_contents($ruta);
        $slug = basename($ruta, '.md');

        [$cabecera, $cuerpo] = self::separar($contenido);

        return new self(
            slug: $slug,
            title: $cabecera['title'] ?? Str::headline($slug),
            body: trim($cuerpo),
            module: $cabecera['module'] ?? null,
            permission: $cabecera['permission'] ?? null,
            keywords: self::lista($cabecera['keywords'] ?? ''),
            related: self::lista($cabecera['related'] ?? ''),
            route: $cabecera['route'] ?? null,
        );
    }

    /** Primeras líneas del cuerpo, para enseñar de qué va sin abrirlo entero. */
    public function excerpt(int $caracteres = 180): string
    {
        // Se quitan los encabezados y las marcas de markdown más ruidosas: un resumen con almohadillas
        // y asteriscos se lee peor que el texto pelado.
        $limpio = preg_replace('/^#+\s*/m', '', $this->body) ?? $this->body;
        $limpio = str_replace(['**', '`', '- '], '', $limpio);

        return Str::limit(trim(preg_replace('/\s+/', ' ', $limpio) ?? $limpio), $caracteres);
    }

    /**
     * Todo el texto sobre el que se busca, con el título y las claves REPETIDOS.
     *
     * No es un truco: el peso lo pone `HelpSearch`. Esto solo junta el texto para poder contar
     * coincidencias en un sitio.
     */
    public function searchableText(): string
    {
        return mb_strtolower($this->title.' '.implode(' ', $this->keywords).' '.$this->body);
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private static function separar(string $contenido): array
    {
        $contenido = ltrim($contenido, "\xEF\xBB\xBF \n\r\t");

        if (! str_starts_with($contenido, '---')) {
            return [[], $contenido];
        }

        $partes = preg_split('/^---\s*$/m', $contenido, 3);

        if ($partes === false || count($partes) < 3) {
            return [[], $contenido];
        }

        $cabecera = [];

        foreach (preg_split('/\R/', trim($partes[1])) ?: [] as $linea) {
            if (! str_contains($linea, ':')) {
                continue;
            }

            [$clave, $valor] = explode(':', $linea, 2);
            $cabecera[trim($clave)] = trim($valor);
        }

        return [$cabecera, $partes[2]];
    }

    /**
     * @return list<string>
     */
    private static function lista(string $valor): array
    {
        return array_values(array_filter(array_map(
            static fn (string $parte): string => mb_strtolower(trim($parte)),
            explode(',', $valor),
        ), static fn (string $parte): bool => $parte !== ''));
    }
}
