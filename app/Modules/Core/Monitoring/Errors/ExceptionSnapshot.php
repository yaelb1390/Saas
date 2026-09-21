<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Errors;

use App\Modules\Core\Support\SecretRedactor;
use Illuminate\Database\QueryException;
use Illuminate\View\ViewException;
use Throwable;

/**
 * Lo que hace falta saber de una excepción para agruparla, ya limpio.
 *
 * Existe para que la decisión de «¿es el mismo fallo?» y la del texto que se guarda salgan de UN solo
 * sitio, sin depender del manejador de errores ni de `bootstrap/app.php`, y para poder probarlas con una
 * excepción cualquiera en la mano.
 *
 * Lo que arregla respecto a la huella anterior (`sha1(clase|fichero:línea)`):
 *
 *  · TODA `QueryException` nace en `vendor/.../Connection.php`, así que todos los errores de SQL caían en
 *    un único grupo, el que fuera la consulta. Aquí el origen es el primer marco PROPIO (el código de la
 *    aplicación que llamó), y para errores de base de datos entran también el SQLSTATE y la sentencia sin
 *    valores.
 *  · El número de línea cambia con cualquier edición y partía en dos el mismo fallo tras cada despliegue.
 *    El origen es ahora ruta + `Clase::método`, sin línea.
 *  · El mensaje de una `QueryException` lleva el SQL CON LOS VALORES sustituidos (`Str::replaceArray`):
 *    un `update users set password = '…'` acababa guardado y en pantalla. El texto que se guarda sale del
 *    mensaje de la excepción de PDO, sin la sentencia, y con la sentencia normalizada aparte.
 */
final class ExceptionSnapshot
{
    public function __construct(
        /** La clase real: una `ViewException` (la de Blade envuelve a otra) se desenvuelve. */
        public readonly string $class,
        /** Lo que se enseña y se guarda: sin credenciales y sin valores de consulta. */
        public readonly string $sampleMessage,
        /** Lo que entra en la huella: el mensaje sin sus datos variables. */
        public readonly string $normalizedMessage,
        /** `ruta/relativa.php@Clase::método` del primer marco propio; null si no hay ninguno. */
        public readonly ?string $origin,
        /** Ruta relativa (sin método) del primer marco propio, para decidir a qué módulo pertenece. */
        public readonly ?string $originPath,
        /** Sin marco propio: solo hay código de terceros, o una vista compilada. */
        public readonly bool $weakOrigin,
        /** SQLSTATE si es un error de base de datos. */
        public readonly ?string $sqlState,
        /** Host al que se llamó, si el mensaje trae una dirección. */
        public readonly ?string $host,
        /** `fichero.php:línea` del sitio donde se lanzó, solo para enseñar. */
        public readonly string $throwSite,
    ) {}

    public static function from(Throwable $e, ?string $basePath = null): self
    {
        $base = self::normalizarRuta($basePath ?? base_path());

        // Una vista de Blade que falla envuelve el error real dentro de una `ViewException`.
        $raiz = $e;

        while ($raiz instanceof ViewException && $raiz->getPrevious() !== null) {
            $raiz = $raiz->getPrevious();
        }

        [$origen, $rutaOrigen, $debil] = self::origen($raiz, $base);

        $sqlState = null;

        if ($raiz instanceof QueryException) {
            [$muestra, $normalizado, $sqlState] = self::deConsulta($raiz);
        } else {
            $crudo = $raiz->getMessage();
            $muestra = mb_substr(SecretRedactor::redact($crudo), 0, 400);
            $normalizado = MessageNormalizer::normalize($crudo);
        }

        return new self(
            class: self::nombreEstable($raiz::class),
            sampleMessage: $muestra,
            normalizedMessage: $normalizado,
            origin: $origen,
            originPath: $rutaOrigen,
            weakOrigin: $debil,
            sqlState: $sqlState,
            host: ServiceResolver::hostFromMessage($raiz->getMessage()),
            throwSite: basename($raiz->getFile()).':'.$raiz->getLine(),
        );
    }

    /** Lo que se guarda en la columna `origin`: el origen estable, o el sitio del `throw` si no lo hay. */
    public function originLabel(): string
    {
        return mb_substr($this->origin ?? $this->throwSite, 0, 255);
    }

    /**
     * @return array{0: string, 1: string, 2: ?string} texto a guardar, texto para la huella y SQLSTATE
     */
    private static function deConsulta(QueryException $e): array
    {
        $previo = $e->getPrevious()?->getMessage() ?? $e->getMessage();

        // Sin el `(Connection: pgsql, Host: …, SQL: …)` que Laravel pega detrás, y sin el DETALLE de
        // PostgreSQL, que repite el valor conflictivo (`Key (email)=(ana@x.com) already exists`).
        $previo = (string) preg_replace('/\s*\(Connection: .*$/s', '', $previo);
        $previo = (string) preg_replace('/\s*DETAIL:.*$/s', '', $previo);

        $sentencia = MessageNormalizer::normalizeSql($e->getSql());
        $estado = preg_match('/^[0-9A-Z]{5}$/', (string) $e->getCode()) === 1 ? (string) $e->getCode() : null;

        $muestra = mb_substr(SecretRedactor::redact($previo).' [SQL: '.mb_substr($sentencia, 0, 160).']', 0, 400);

        return [$muestra, 'sqlstate '.($estado ?? '?').' | '.$sentencia, $estado];
    }

    /**
     * El primer sitio del código PROPIO implicado en el error.
     *
     * Un marco de la traza dice DESDE DÓNDE se llamó (`file`/`line`) y A QUÉ (`class`/`function`). Si el
     * error se lanzó en código propio, el método es el del primer marco; si se lanzó en una librería, el
     * sitio propio es el primer marco con fichero propio, y el método que lo contiene es el del marco
     * siguiente.
     *
     * @return array{0: ?string, 1: ?string, 2: bool} origen, ruta y si es débil
     */
    private static function origen(Throwable $e, string $base): array
    {
        $traza = $e->getTrace();
        $lanzadoEn = self::normalizarRuta($e->getFile());

        if (self::esPropio($lanzadoEn, $base)) {
            $ruta = self::relativa($lanzadoEn, $base);

            return [self::conMetodo($ruta, $traza[0] ?? null), $ruta, false];
        }

        foreach ($traza as $i => $marco) {
            $fichero = isset($marco['file']) ? self::normalizarRuta((string) $marco['file']) : null;

            if ($fichero !== null && self::esPropio($fichero, $base)) {
                $ruta = self::relativa($fichero, $base);

                return [self::conMetodo($ruta, $traza[$i + 1] ?? null), $ruta, false];
            }
        }

        return [null, null, true];
    }

    /**
     * @param  array<string, mixed>|null  $marco
     */
    private static function conMetodo(string $ruta, ?array $marco): string
    {
        $funcion = isset($marco['function']) ? (string) $marco['function'] : '';

        if ($funcion === '') {
            return $ruta;
        }

        $clase = isset($marco['class']) ? self::nombreEstable((string) $marco['class']).'::' : '';

        return $ruta.'@'.$clase.self::nombreEstable($funcion);
    }

    /**
     * Quita del nombre lo que cambia sin que el código cambie.
     *
     * PHP 8.4 escribe las funciones anónimas como `{closure:Clase::método():42}` —con la LÍNEA dentro— y
     * PHP 8.3 como `{closure}`: sin esto, la misma huella salía distinta en local (8.4) y en producción
     * (8.3), y tras cada edición que moviera la línea. Y una clase anónima lleva la ruta y la línea en su
     * nombre.
     */
    private static function nombreEstable(string $nombre): string
    {
        $nombre = (string) preg_replace('/\{closure:[^}]*\}/', '{closure}', $nombre);
        $anonima = strpos($nombre, '@anonymous');

        return $anonima === false ? $nombre : substr($nombre, 0, $anonima + strlen('@anonymous'));
    }

    /** Un fichero de la aplicación: bajo la raíz y ni de terceros ni una vista de Blade ya compilada. */
    private static function esPropio(string $ruta, string $base): bool
    {
        // Con la barra final: `/var/www/html2/x.php` no es de la aplicación que vive en `/var/www/html`.
        return $ruta !== ''
            && str_starts_with(strtolower($ruta), strtolower($base).'/')
            && ! str_contains($ruta, '/vendor/')
            && ! str_contains($ruta, '/storage/framework/views/');
    }

    private static function relativa(string $ruta, string $base): string
    {
        return ltrim(substr($ruta, strlen($base)), '/');
    }

    /** Con `/` siempre: en Windows las rutas llevan `\` y la misma huella saldría distinta. */
    private static function normalizarRuta(string $ruta): string
    {
        return rtrim(str_replace('\\', '/', $ruta), '/');
    }
}
