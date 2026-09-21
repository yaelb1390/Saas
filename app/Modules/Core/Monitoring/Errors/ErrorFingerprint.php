<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Errors;

/**
 * La huella de un error: lo que hace que dos fallos sean «el mismo».
 *
 * Es pura (sin base de datos, sin red, sin estado) porque es la decisión más delicada de todo el
 * agrupado y tiene que poder probarse a fondo: si agrupa de más, dos problemas distintos se esconden
 * uno dentro del otro; si agrupa de menos, un fallo que ocurre mil veces llena la pantalla de mil grupos.
 *
 * Entran, por este orden:
 *
 *  · la CLASE de la excepción;
 *  · el MENSAJE normalizado (sin los ids, correos y horas de esa vez: ver `MessageNormalizer`), o, para un
 *    error de base de datos, el SQLSTATE y la sentencia sin valores;
 *  · el ORIGEN: ruta + `Clase::método` del primer código propio implicado, SIN línea;
 *  · el SERVICIO al que pertenece.
 *
 * La RUTA de la petición NO entra, a propósito: el mismo fallo de Evolution que ocurre en el webhook, en
 * un job y en el panel es UN problema, y meter la ruta lo partiría en tres. Solo entra como desempate
 * cuando el origen es débil (no hay ni una línea de código propio: todo es de terceros o una vista
 * compilada) y por tanto no queda nada más que distinga un fallo de otro.
 *
 * La huella lleva el prefijo `v2_` y mide lo mismo que la anterior (40 caracteres): así no puede
 * coincidir con ninguna de las antiguas, que eran un `sha1` a secas, y la restricción de unicidad de la
 * columna no cambia. Los grupos antiguos siguen ahí como «histórico v1» y no se mezclan con los nuevos.
 */
final class ErrorFingerprint
{
    public const VERSION = 2;

    public static function make(ExceptionSnapshot $snapshot, string $service, ?string $routeName): string
    {
        $partes = [
            'v'.self::VERSION,
            $snapshot->class,
            $snapshot->normalizedMessage,
            $snapshot->origin ?? 'sin-origen',
            $service,
        ];

        if ($snapshot->weakOrigin && $routeName !== null) {
            $partes[] = 'ruta:'.$routeName;
        }

        // 3 + 37 = 40 caracteres, como la columna que ya existe.
        return 'v'.self::VERSION.'_'.substr(hash('sha256', implode('|', $partes)), 0, 37);
    }
}
