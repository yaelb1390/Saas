<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Tacha lo que parezca una credencial ANTES de guardarlo o de enseñarlo.
 *
 * Existe porque el registro de sucesos y el de errores guardan texto que no escribimos nosotros: el
 * mensaje de una excepción, el detalle de una respuesta de API, la dirección de una petición. Todo eso
 * trae credenciales más veces de las que parece —la clave de una API dentro de su propio error, el
 * `?secret=` de un webhook, la contraseña dentro del SQL de una consulta fallida— y guardarlas aquí
 * sería filtrarlas a una pantalla y a todas las copias de seguridad.
 *
 * Antes había DOS copias de la misma expresión (en `SystemEvent` y en `ErrorEvent`), repetidas a
 * propósito para que una tabla no dependiera de la otra. Eso obligaba a acordarse de tocar las dos cada
 * vez que aparecía un formato de clave nuevo, y solo conocían prefijos de proveedor. Aquí hay una sola,
 * sin estado ni dependencias, que no conoce ninguna tabla: por eso pueden compartirla las dos.
 *
 * Tacha de DOS maneras, porque un secreto llega de dos: por su APARIENCIA (el valor: `sk-…`, un JWT) y
 * por su NOMBRE (la clave `password` de un contexto, el parámetro `?token=` de una dirección).
 */
final class SecretRedactor
{
    public const MASCARA = '***';

    /** Prefijos de claves de proveedores que ya nos hemos encontrado. Era la expresión original. */
    private const CLAVES_CONOCIDAS = '/(AIza|sk-|sk_|xkeysib-|xsmtpsib-|polar_oat_|Bearer\s+)[A-Za-z0-9_\-]{8,}/';

    /** Un JWT: tres tramos en base64url, el primero empieza siempre por `eyJ` (`{"`). */
    private const JWT = '/eyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/';

    /** Claves de acceso de AWS (S3 y compatibles). */
    private const AWS = '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/';

    /** Usuario y contraseña dentro de una dirección: `postgres://usuario:clave@host`. */
    private const CREDENCIALES_EN_URL = '#(://)[^/\s:@]+:[^/\s@]+@#';

    /**
     * `password=abc`, `"token": "abc"`, `api_key: abc`, `secret=abc`…
     *
     * El `(?<![A-Za-z0-9])` evita tachar palabras que solo CONTIENEN la clave (`mytoken`), y el
     * `(?!\*\*\*)` evita tachar dos veces lo que ya se tachó por otra vía.
     */
    private const PAR_CLAVE_VALOR = '/(?<![A-Za-z0-9])(password|passwd|pwd|secret|token|api[_-]?key|apikey|authorization|access[_-]?token|client[_-]?secret|signature)(["\']?\s*[:=]\s*["\']?)(?!\*\*\*)([^\s"\'&,;)]+)/i';

    /**
     * Nombres de clave cuyo valor NUNCA se guarda, sea cual sea.
     *
     * Se compara sobre la clave en `snake_case` y con límites de palabra: `client_secret` y `apiKey` sí,
     * `passenger` y `tokenizer` no.
     */
    private const NOMBRES_SENSIBLES = '/(^|[_\-.])(pass|password|passwd|pwd|secret|token|api[_-]?key|apikey|authorization|cookie|signature|credentials?|private[_-]?key)($|[_\-.])/i';

    /**
     * Tacha las credenciales de un texto.
     *
     * Si una expresión falla (un texto enorme que la desborda), NO devuelve el original: devuelve la
     * máscara. Perder un mensaje es recuperable; publicar una clave, no.
     */
    public static function redact(string $texto): string
    {
        if ($texto === '') {
            return $texto;
        }

        foreach ([self::CLAVES_CONOCIDAS, self::JWT, self::AWS] as $patron) {
            $texto = preg_replace($patron, self::MASCARA, $texto);

            if ($texto === null) {
                return self::MASCARA;
            }
        }

        $texto = preg_replace(self::CREDENCIALES_EN_URL, '$1'.self::MASCARA.'@', $texto);

        if ($texto === null) {
            return self::MASCARA;
        }

        $texto = preg_replace(self::PAR_CLAVE_VALOR, '$1$2'.self::MASCARA, $texto);

        return $texto ?? self::MASCARA;
    }

    /**
     * Tacha un detalle estructurado (el contexto de un suceso).
     *
     * El valor de una clave sensible se sustituye entero, sea texto o un arreglo. Los demás textos se
     * tachan por apariencia y se recortan DESPUÉS de tachar: recortar antes partía una clave por la
     * mitad y la dejaba a medias, sin que la expresión la reconociera.
     *
     * @param  array<array-key, mixed>  $datos
     * @return array<array-key, mixed>
     */
    public static function redactArray(array $datos, int $largoMaximo = 500): array
    {
        $limpio = [];

        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && self::esClaveSensible($clave)) {
                $limpio[$clave] = self::MASCARA;

                continue;
            }

            if (is_array($valor)) {
                $limpio[$clave] = self::redactArray($valor, $largoMaximo);
            } elseif (is_string($valor)) {
                $limpio[$clave] = mb_substr(self::redact($valor), 0, $largoMaximo);
            } else {
                $limpio[$clave] = $valor;
            }
        }

        return $limpio;
    }

    /**
     * Una dirección sin lo que no debe guardarse: sin usuario ni contraseña, sin fragmento y con los
     * VALORES de la consulta tachados (quedan los nombres de los parámetros).
     *
     * Hace falta porque el webhook de Evolution acepta su secreto por `?secret=`: una excepción en esa
     * petición guardaba la dirección completa, secreto incluido. Se tachan todos los valores y no solo
     * los que «parecen» secretos: cualquier parámetro puede llevar un dato personal, y para saber QUÉ
     * dirección falló sobra con ver por dónde entró.
     */
    public static function sanitizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $partes = parse_url($url);

        if ($partes === false) {
            // Ilegible: se corta en la consulta y se tacha lo que quede.
            return mb_substr(self::redact((string) strtok($url, '?')), 0, 500);
        }

        $direccion = isset($partes['scheme']) ? $partes['scheme'].'://' : '';
        $direccion .= $partes['host'] ?? '';
        $direccion .= isset($partes['port']) ? ':'.$partes['port'] : '';
        $direccion .= $partes['path'] ?? '';

        if (isset($partes['query']) && $partes['query'] !== '') {
            parse_str($partes['query'], $parametros);

            $direccion .= '?'.implode('&', array_map(
                static fn (string|int $nombre): string => $nombre.'='.self::MASCARA,
                array_keys($parametros),
            ));
        }

        return mb_substr(self::redact($direccion), 0, 500);
    }

    private static function esClaveSensible(string $clave): bool
    {
        // `clientSecret` y `apiKey` → `client_secret` y `api_key`.
        $snake = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $clave));

        return preg_match(self::NOMBRES_SENSIBLES, $snake) === 1;
    }
}
