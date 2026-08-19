<?php

declare(strict_types=1);

namespace App\Modules\Help\Services;

use App\Models\User;
use App\Modules\Core\Models\Company;
use App\Modules\Help\Support\HelpArticle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Encuentra el artículo que contesta una pregunta, entre los que ESE usuario puede ver.
 *
 * Búsqueda léxica con pesos, no embeddings. Para treinta artículos cortos acierta más que un vector
 * de 128 dimensiones sacado de un hash —lo único que puede dar el proveedor local sin clave de API— y
 * además es determinista: se comporta igual en local y en producción, y sus fallos se reproducen.
 *
 * El problema real de buscar por palabras es el vocabulario: el cliente escribe «devolver un
 * producto» y el manual dice «anular una venta». Por eso el peso fuerte está en `keywords`, que es
 * donde el artículo declara CÓMO LO LLAMA LA GENTE. Si un artículo no aparece cuando debería, casi
 * siempre la respuesta es añadir el sinónimo, no tocar esta clase.
 */
final class HelpSearch
{
    /**
     * Una palabra clave de VARIAS palabras que aparece entera en la pregunta.
     *
     * Es la señal más fuerte de todas y por eso pesa más que el título: «no aparece» o «devolver lo
     * que compró» no son palabras sueltas que puedan coincidir por casualidad, son la forma exacta en
     * que alguien plantea ese problema. Sin esto, «no me aparece el módulo de préstamos» se iba al
     * artículo de préstamos —porque «préstamos» está en su título— en vez de al de planes y módulos,
     * que es el que contesta.
     */
    private const PESO_FRASE = 14;

    /** Coincidir en el título es la señal más fuerte de que el artículo va de eso. */
    private const PESO_TITULO = 10;

    /** Una palabra clave es una declaración explícita de «así lo llama el cliente». */
    private const PESO_CLAVE = 6;

    /** En el cuerpo aparece de todo, así que suma poco y solo desempata. */
    private const PESO_CUERPO = 1;

    /** Por debajo de esto no se enseña: es preferible decir «no lo encuentro» que dar algo al azar. */
    private const UMBRAL = 6;

    /**
     * Palabras que aparecen en casi toda pregunta y no distinguen nada. Sin quitarlas, «¿cómo hago
     * para...?» puntúa contra todos los artículos por igual.
     *
     * @var list<string>
     */
    private const VACIAS = [
        'como', 'cómo', 'donde', 'dónde', 'que', 'qué', 'cual', 'cuál', 'quien', 'quién',
        'para', 'por', 'con', 'sin', 'del', 'las', 'los', 'una', 'uno', 'unos', 'unas',
        'hago', 'hacer', 'puedo', 'poder', 'quiero', 'necesito', 'sirve', 'hay', 'esta', 'este',
        'and', 'the', 'mas', 'más', 'muy', 'todo', 'toda', 'algo', 'aqui', 'aquí', 'sistema',
        // De tres letras, admitidas desde que se bajó el corte de longitud: aportan cero y aparecen
        // en cualquier pregunta.
        'son', 'ser', 'sus', 'ese', 'esa', 'eso', 'asi', 'así', 'ver', 'dos', 'muy', 'año', 'aún',
    ];

    public function __construct(private readonly HelpLibrary $library) {}

    /**
     * Artículos que responden a la pregunta, del más relevante al menos.
     *
     * @return Collection<int, array{article: HelpArticle, score: int}>
     */
    public function search(string $pregunta, ?User $usuario = null, int $limite = 4): Collection
    {
        $palabras = $this->palabras($pregunta);

        if ($palabras === []) {
            return collect();
        }

        $normalizada = $this->normalizar($pregunta);

        return $this->visibles($usuario)
            ->map(fn (HelpArticle $articulo): array => [
                'article' => $articulo,
                'score' => $this->puntuar($articulo, $palabras, $normalizada),
            ])
            ->filter(static fn (array $fila): bool => $fila['score'] >= self::UMBRAL)
            ->sortByDesc('score')
            ->take($limite)
            ->values();
    }

    /**
     * Artículos que este usuario puede ver, agrupados por módulo, para el índice de la pantalla.
     *
     * @return Collection<string, Collection<int, HelpArticle>>
     */
    public function index(?User $usuario = null): Collection
    {
        return $this->visibles($usuario)
            ->sortBy(static fn (HelpArticle $a): string => $a->title)
            ->groupBy(static fn (HelpArticle $a): string => $a->module ?? 'general');
    }

    /**
     * El mejor artículo que responde la pregunta pero que este usuario NO puede ver, y por qué.
     *
     * Sin esto, preguntar por algo que no está en tu plan devuelve «no lo encuentro», que es falso y
     * además deja al cliente buscando en el menú una pantalla que no existe. El artículo SÍ contesta
     * su pregunta; lo que pasa es otra cosa, y esa otra cosa se puede decir.
     *
     * Se devuelve el motivo y no solo el artículo porque los dos motivos piden respuestas opuestas:
     * al que le falta el módulo se le ofrece verlo; al que le falta el permiso, JAMÁS —el plan ya lo
     * incluye y cambiarlo no es su decisión—, se le manda al dueño.
     *
     * @return array{article: HelpArticle, motivo: string, score: int}|null
     */
    public function fueraDeAlcance(string $pregunta, ?User $usuario): ?array
    {
        if ($usuario === null) {
            return null;
        }

        $palabras = $this->palabras($pregunta);

        if ($palabras === []) {
            return null;
        }

        $normalizada = $this->normalizar($pregunta);

        return $this->library->all()
            ->map(fn (HelpArticle $articulo): array => [
                'article' => $articulo,
                'motivo' => $this->motivoDeExclusion($articulo, $usuario),
                'score' => $this->puntuar($articulo, $palabras, $normalizada),
            ])
            ->filter(static fn (array $fila): bool => $fila['motivo'] !== null && $fila['score'] >= self::UMBRAL)
            ->sortByDesc('score')
            ->first();
    }

    /**
     * El manual recortado a lo que este usuario tiene delante.
     *
     * Sin usuario (o sin empresa activa) se devuelve el manual entero: es lo que necesitan los tests
     * y la pantalla pública si algún día la hay.
     *
     * @return Collection<string, HelpArticle>
     */
    private function visibles(?User $usuario): Collection
    {
        if ($usuario === null) {
            return $this->library->all();
        }

        return $this->library->all()->filter(
            fn (HelpArticle $articulo): bool => $this->motivoDeExclusion($articulo, $usuario) === null,
        );
    }

    /**
     * Por qué este usuario no puede ver este artículo, o null si sí puede.
     *
     * Los dos filtros importan, y por motivos distintos:
     *
     *  - El MÓDULO: explicarle los préstamos a quien no los contrató es vender, no ayudar, y encima
     *    le hace buscar en el menú una pantalla que no existe.
     *  - El PERMISO: contarle a un cajero cómo anular ventas —que no puede— es peor que no contestar,
     *    porque le hace pedirle al dueño algo que creerá que está haciendo mal.
     *
     * El orden importa: se mira primero el módulo. Si a la empresa le falta el módulo, al usuario le
     * faltará también el permiso, y decirle «pídeselo al dueño» sería mandarlo a pedir algo que el
     * dueño tampoco tiene.
     *
     * @return string|null 'modulo' | 'permiso' | null
     */
    private function motivoDeExclusion(HelpArticle $articulo, User $usuario): ?string
    {
        if ($articulo->module !== null && ! $this->tieneModulo($usuario->company, $articulo->module)) {
            return 'modulo';
        }

        if ($articulo->permission !== null && ! Gate::forUser($usuario)->allows($articulo->permission)) {
            return 'permiso';
        }

        return null;
    }

    private function tieneModulo(?Company $empresa, string $module): bool
    {
        // Sin empresa no se puede afirmar que le falte el módulo; se deja pasar y el permiso decide.
        return $empresa === null || $empresa->hasModule($module);
    }

    /**
     * @param  list<string>  $palabras  las palabras útiles de la pregunta
     * @param  string  $pregunta  la pregunta entera normalizada, para buscar frases
     */
    private function puntuar(HelpArticle $articulo, array $palabras, string $pregunta): int
    {
        // El artículo se normaliza IGUAL que la pregunta. Si solo se le quitaran los acentos a una de
        // las dos partes, «devolucion» no encontraría «devolución», que es justo la palabra que la
        // gente escribe sin tilde y por la que más se va a buscar.
        $titulo = $this->normalizar($articulo->title);
        $claves = $this->normalizar(implode(' ', $articulo->keywords));
        $cuerpo = $this->normalizar($articulo->body);

        $total = 0;

        // Primero las frases: una clave de varias palabras que aparece entera en la pregunta es lo
        // más parecido a que el cliente haya dicho el nombre del problema.
        foreach ($articulo->keywords as $clave) {
            $clave = $this->normalizar($clave);

            if (str_contains($clave, ' ') && str_contains($pregunta, $clave)) {
                $total += self::PESO_FRASE;
            }
        }

        foreach ($palabras as $palabra) {
            if (str_contains($titulo, $palabra)) {
                $total += self::PESO_TITULO;
            }

            if (str_contains($claves, $palabra)) {
                $total += self::PESO_CLAVE;
            }

            if (str_contains($cuerpo, $palabra)) {
                $total += self::PESO_CUERPO;
            }
        }

        return $total;
    }

    /**
     * Palabras útiles de la pregunta: sin acentos, sin las vacías y sin las de menos de cuatro letras.
     *
     * Se quitan los acentos porque la gente escribe «anular una devolucion» sin tilde y el manual la
     * lleva; comparar tal cual perdería la coincidencia justo en la palabra que importa.
     *
     * @return list<string>
     */
    private function palabras(string $texto): array
    {
        $texto = $this->normalizar($texto);

        $vacias = array_map(fn (string $p): string => $this->normalizar($p), self::VACIAS);

        // Tres letras y no cuatro: «luz», «pos», «ncf» y «606» son justo las palabras que más
        // distinguen de qué va la pregunta, y con el corte en cuatro se descartaban. «Dónde apunto
        // la factura de la luz» se iba a las facturas de compra por perder «luz».
        $palabras = array_filter(
            preg_split('/\s+/', $texto) ?: [],
            static fn (string $p): bool => mb_strlen($p) >= 3 && ! \in_array($p, $vacias, true),
        );

        // Se buscan también en singular: «ventas» tiene que encontrar «venta», y «gastos», «gasto».
        $conSingular = [];

        foreach ($palabras as $palabra) {
            $conSingular[] = $palabra;

            if (mb_strlen($palabra) > 4 && str_ends_with($palabra, 's')) {
                $conSingular[] = mb_substr($palabra, 0, -1);
            }
        }

        return array_values(array_unique($conSingular));
    }

    /**
     * Minúsculas, sin acentos y sin puntuación. Es la forma en la que se comparan pregunta y artículo,
     * y tiene que ser LA MISMA para los dos.
     */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $texto) ?? $texto;
    }
}
