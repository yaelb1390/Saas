<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Errors;

use PDOException;

/**
 * ¿De qué servicio o integración es este error (o este suceso)?
 *
 * Sirve para dos cosas: que la huella de un error incluya de qué servicio viene (el mismo texto de
 * «Connection timed out» contra Evolution y contra Polar son problemas distintos), y para poder filtrar
 * y agrupar la pantalla por servicio («¿qué falla, Evolution o la base de datos?»).
 *
 * Es una heurística deliberada y barata, sin red ni base de datos. Para un error, por orden:
 *
 *  1. la CLASE de la excepción (base de datos, Redis, correo, almacenamiento);
 *  2. el HOST al que se llamó, si el mensaje trae una dirección (tabla en `bmos.monitoreo.errores.hosts`
 *     más el de Evolution, que es propio de cada instalación);
 *  3. la RUTA del código propio donde ocurrió (el módulo de WhatsApp, el de IA…);
 *  4. `app`, si nada de lo anterior lo aclara.
 */
final class ServiceResolver
{
    /** El servicio de cada familia de sucesos, por el prefijo de su tipo. */
    private const PREFIJOS_DE_SUCESO = [
        'auth.' => 'auth',
        'task.' => 'scheduler',
        'mail.' => 'mail',
        'subscription.' => 'polar',
        'platform.' => 'platform',
        'queue.' => 'queue',
        'incident.' => 'incidents',
    ];

    /**
     * Palabras del mensaje de un aviso de integración y el servicio que delatan. Es un respaldo para
     * los sucesos que ya se escriben sin decir su servicio; el orden importa (el primero que aparece).
     */
    private const PALABRAS_DE_SUCESO = [
        'polar' => 'polar',
        'zernio' => 'zernio',
        'redes' => 'zernio',
        'bienvenida' => 'zernio',
        'sentimiento' => 'ai',
        'inteligencia' => 'ai',
        'whatsapp' => 'evolution',
        'evolution' => 'evolution',
    ];

    /** Servicios a los que se les puede preguntar por el «prefijo» de una ruta propia. */
    private const RUTAS_PROPIAS = [
        'app/Modules/WhatsApp/' => 'whatsapp',
        'app/Modules/AI/' => 'ai',
        'app/Modules/Social/' => 'zernio',
        'app/Modules/Core/Services/Polar' => 'polar',
        'app/Modules/Core/Http/Controllers/Polar' => 'polar',
    ];

    public static function forSnapshot(ExceptionSnapshot $snapshot): string
    {
        return self::forParts($snapshot->class, $snapshot->host, $snapshot->originPath);
    }

    /**
     * @param  string  $clase  la excepción
     * @param  string|null  $host  a quién se llamó, si se sabe
     * @param  string|null  $rutaPropia  ruta relativa del primer marco propio
     */
    public static function forParts(string $clase, ?string $host, ?string $rutaPropia): string
    {
        if (str_starts_with($clase, 'Illuminate\\Database\\') || is_a($clase, PDOException::class, true)) {
            return 'database';
        }

        if (str_starts_with($clase, 'Predis\\') || str_contains($clase, 'RedisException')) {
            return 'redis';
        }

        if (str_starts_with($clase, 'Symfony\\Component\\Mailer\\') || str_starts_with($clase, 'Illuminate\\Mail\\')) {
            return 'mail';
        }

        if (str_starts_with($clase, 'League\\Flysystem\\')) {
            return 'storage';
        }

        if ($host !== null && ($porHost = self::porHost($host)) !== null) {
            return $porHost;
        }

        if ($rutaPropia !== null) {
            foreach (self::RUTAS_PROPIAS as $prefijo => $servicio) {
                if (str_starts_with($rutaPropia, $prefijo)) {
                    return $servicio;
                }
            }
        }

        return 'app';
    }

    /**
     * El servicio de un suceso del registro, por su tipo y, si el tipo no basta, por su mensaje.
     * Null si no se puede saber: es mejor no decir nada que decir uno falso.
     */
    public static function forEvent(string $type, string $message): ?string
    {
        foreach (self::PREFIJOS_DE_SUCESO as $prefijo => $servicio) {
            if (str_starts_with($type, $prefijo)) {
                return $servicio;
            }
        }

        if (str_starts_with($type, 'integration.') || str_starts_with($type, 'webhook.')) {
            $texto = mb_strtolower($message);

            foreach (self::PALABRAS_DE_SUCESO as $palabra => $servicio) {
                if (str_contains($texto, $palabra)) {
                    return $servicio;
                }
            }
        }

        return null;
    }

    /**
     * A qué host se llamó, según lo que dice el mensaje de error; null si no trae ninguna dirección.
     */
    public static function hostFromMessage(string $mensaje): ?string
    {
        if (preg_match_all('#https?://([^/\s:\'"?]+)#i', $mensaje, $hosts) === 0) {
            return null;
        }

        foreach ($hosts[1] as $host) {
            $host = strtolower($host);

            // El aviso de cURL trae siempre un enlace a su documentación, que no es a quién se llamó.
            if (! in_array($host, ['curl.haxx.se', 'curl.se'], true)) {
                return $host;
            }
        }

        return null;
    }

    private static function porHost(string $host): ?string
    {
        $tabla = (array) config('bmos.monitoreo.errores.hosts', []);

        // El host de Evolution es de cada instalación: sale de su configuración, no de una tabla fija.
        $evolution = parse_url((string) config('evolution.base_url', ''), PHP_URL_HOST);

        if (is_string($evolution) && $evolution !== '') {
            $tabla[strtolower($evolution)] = 'evolution';
        }

        foreach ($tabla as $patron => $servicio) {
            $patron = strtolower((string) $patron);

            if ($host === $patron || str_ends_with($host, '.'.$patron)) {
                return (string) $servicio;
            }
        }

        return null;
    }
}
