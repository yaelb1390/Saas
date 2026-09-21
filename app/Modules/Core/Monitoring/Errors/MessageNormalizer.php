<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Errors;

use App\Modules\Core\Support\SecretRedactor;

/**
 * Convierte el mensaje de un error en algo que se parezca a sí mismo cada vez que ocurre.
 *
 * El mensaje de una excepción lleva los datos de ESA vez: el id de un pedido, un correo, la hora, la
 * dirección con su número. Si entrara tal cual en la huella, «Timeout calling API /12345» y
 * «Timeout calling API /67890» serían dos errores distintos, y un fallo que ocurre mil veces con mil
 * ids acabaría en mil grupos: justo lo que agrupar quiere evitar.
 *
 * Aquí los datos variables se sustituyen por marcadores (`{n}`, `{uuid}`, `{email}`…). Lo que sirve
 * para DISTINGUIR un fallo de otro se conserva: `SQLSTATE[23505]`, `cURL error 28`, `status code 401`,
 * el nombre de la columna que no existe, el host al que se llamó. Un tiempo de espera agotado (28) y una
 * resolución de nombre fallida (6) son problemas distintos aunque el resto del texto coincida.
 *
 * Es una función pura —sin base de datos, sin red, sin estado— para poder probarla a fondo.
 */
final class MessageNormalizer
{
    /** Cuánto del mensaje entra en la huella: lo demás es ruido y hace la huella frágil. */
    private const LARGO_MAXIMO = 200;

    /**
     * Fragmentos cuyo NÚMERO es justamente el dato que distingue un error de otro. Se apartan antes de
     * sustituir los números y se restauran después.
     */
    private const PROTEGIDOS = '/SQLSTATE\[[A-Za-z0-9]+\]|cURL error \d+|status code \d+|HTTP(?:\/\d(?:\.\d)?)? [1-5]\d{2}\b/i';

    public static function normalize(string $mensaje): string
    {
        // Lo primero, quitar las credenciales: la huella se guarda y el texto normalizado no debe
        // arrastrar una clave aunque nunca se enseñe.
        $texto = SecretRedactor::redact(mb_substr($mensaje, 0, 1000));

        /** @var list<string> $apartados */
        $apartados = [];

        $texto = self::apartar($texto, self::PROTEGIDOS, $apartados);

        // Direcciones: se conserva el host (dice a qué servicio se llamó) y la ruta sin ids; la consulta
        // se va entera.
        $texto = preg_replace_callback(
            '#https?://[^\s\'"<>)\]]+#i',
            static function (array $m) use (&$apartados): string {
                $direccion = rtrim($m[0], '.,;:');
                $cola = substr($m[0], strlen($direccion));
                $partes = parse_url($direccion);

                $normalizada = ($partes === false || ! isset($partes['host']))
                    ? '{url}'
                    : ($partes['scheme'] ?? 'https').'://'.strtolower($partes['host']).self::rutaSinIds($partes['path'] ?? '');

                $apartados[] = $normalizada;

                return "\x01".self::letras(count($apartados) - 1)."\x02".$cola;
            },
            $texto,
        ) ?? $texto;

        $sustituciones = [
            // Correos
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '{email}',
            // Identificadores largos
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
            '/\b[0-9A-HJKMNP-TV-Z]{26}\b/' => '{ulid}',
            // Fechas y horas ANTES que las direcciones IPv6 (una hora `10:20:30` parece una)
            '/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?/' => '{ts}',
            '/\b\d{4}-\d{2}-\d{2}\b/' => '{date}',
            '/\b\d{1,2}:\d{2}:\d{2}\b/' => '{time}',
            // Direcciones IP
            '/\b\d{1,3}(?:\.\d{1,3}){3}\b/' => '{ip}',
            '/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}\b/i' => '{ip}',
            // Cadenas hexadecimales largas (hashes, tokens ya recortados)
            '/\b[0-9a-f]{12,}\b/i' => '{hex}',
        ];

        foreach ($sustituciones as $patron => $marcador) {
            $texto = preg_replace($patron, $marcador, $texto) ?? $texto;
        }

        // Lo entrecomillado: un identificador (una columna, una clase) DICE cuál es el fallo y se
        // conserva; un dato con espacios, cifras o símbolos («'Ana Pérez'», «'x@y.com'») es de esta vez.
        $texto = preg_replace_callback(
            '/\'([^\'\\\\]*)\'|"([^"\\\\]*)"/',
            static function (array $m): string {
                $contenido = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
                $comilla = ($m[1] ?? '') !== '' ? "'" : '"';

                return preg_match('/^[A-Za-z_][A-Za-z0-9_.\\\\\-]*$/', $contenido) === 1
                    ? $comilla.$contenido.$comilla
                    : '{str}';
            },
            $texto,
        ) ?? $texto;

        // Números sueltos
        $texto = preg_replace('/\b\d+(?:[.,]\d+)*\b/', '{n}', $texto) ?? $texto;

        // Se devuelve lo apartado, en orden inverso por si alguno contiene el marcador de otro.
        foreach (array_reverse(array_keys($apartados)) as $i) {
            $texto = str_replace("\x01".self::letras($i)."\x02", $apartados[$i], $texto);
        }

        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));

        return mb_substr($texto, 0, self::LARGO_MAXIMO);
    }

    /**
     * Una sentencia SQL sin sus valores: dos consultas iguales con datos distintos dan lo mismo.
     *
     * Los valores (`'ana@x.com'`, `42`, `$1`) pasan a `?`, y las listas (`in (?, ?, ?)`, los `values` de
     * una inserción múltiple) a una sola. Sirve para la huella de un error de base de datos y, más
     * adelante, para agrupar las consultas lentas.
     */
    public static function normalizeSql(string $sql, int $largoMaximo = 300): string
    {
        $sql = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $sql)));

        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/\$\d+/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?)', $sql) ?? $sql;
        $sql = preg_replace('/\(\?\)(?:\s*,\s*\(\?\))+/', '(?)', $sql) ?? $sql;

        return mb_substr($sql, 0, $largoMaximo);
    }

    /**
     * La ruta de una dirección sin los ids: `/api/12345/items` → `/api/{n}/items`.
     */
    private static function rutaSinIds(string $ruta): string
    {
        if ($ruta === '' || $ruta === '/') {
            return '';
        }

        $segmentos = array_map(static function (string $segmento): string {
            return match (true) {
                $segmento === '' => '',
                ctype_digit($segmento) => '{n}',
                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segmento) === 1 => '{uuid}',
                preg_match('/^[0-9a-f]{12,}$/i', $segmento) === 1 => '{hex}',
                default => $segmento,
            };
        }, explode('/', $ruta));

        return implode('/', $segmentos);
    }

    /**
     * Aparta los fragmentos que casan con el patrón y deja un marcador en su lugar.
     *
     * El marcador lleva LETRAS y no la posición en cifras: si llevara cifras, la sustitución de números
     * que viene después las tocaría y no habría forma de restaurar el fragmento.
     *
     * @param  list<string>  $apartados
     */
    private static function apartar(string $texto, string $patron, array &$apartados): string
    {
        return preg_replace_callback(
            $patron,
            static function (array $m) use (&$apartados): string {
                $apartados[] = $m[0];

                return "\x01".self::letras(count($apartados) - 1)."\x02";
            },
            $texto,
        ) ?? $texto;
    }

    /** 0 → «a», 1 → «b», 26 → «ba»…: un número escrito solo con letras. */
    private static function letras(int $n): string
    {
        $letras = '';

        do {
            $letras = chr(97 + ($n % 26)).$letras;
            $n = intdiv($n, 26);
        } while ($n > 0);

        return $letras;
    }
}
