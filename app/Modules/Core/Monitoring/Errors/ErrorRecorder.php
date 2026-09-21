<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Errors;

use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Support\SecretRedactor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Anota un error en su grupo, sin perder de qué empresa y de qué usuario viene.
 *
 * El grupo (`error_events`) cuenta cuántas veces ocurrió el error. Cada empresa y cada usuario afectados
 * tienen su propia fila (`error_event_companies`, `error_event_users`) con su cuenta. Así «148 veces» se
 * convierte en «148 veces, 12 empresas, 31 usuarios», y en «47 de Empresa ABC, 31 de Empresa XYZ…».
 *
 * Cómo escribe, y por qué así:
 *
 *  · Primero SUMA al grupo y solo si no existía lo inserta. Al revés —leer, decidir, escribir— dos
 *    peticiones fallando a la vez crearían dos filas con la misma huella, la restricción única rechazaría
 *    una y el registro de errores se convertiría en otro error. El mismo patrón, para cada fila hija.
 *  · La empresa y el usuario del grupo (`company_id`, `user_id`) significan «el último conocido»: solo se
 *    pisan si llegan con valor. Antes un error de consola —sin empresa— borraba la última empresa que se
 *    sabía. El desglose de verdad está en las filas hijas.
 *  · Un grupo `resolved` que vuelve a ocurrir pasa a `active`: alguien lo dio por arreglado y no lo estaba.
 *
 * Tiene DOS modos, y elige solo:
 *
 *  · v2, cuando la migración ya se aplicó (las tablas y columnas nuevas existen);
 *  · legacy, cuando no. Aquí las migraciones se aplican a mano y el código sale antes: hasta ese día el
 *    registro tiene que seguir funcionando EXACTAMENTE como hasta ahora, no dejar de guardar errores.
 *
 * Es una instancia (registrada como singleton) y no estática por su tope: como mucho `MAX_POR_VENTANA`
 * anotaciones por minuto y por proceso. Un bucle que reporta mil excepciones no puede convertir el
 * registro de errores en mil escrituras a la base de datos, que es justo lo que ya está fallando.
 * Al ser del contenedor y no de la clase, se reinicia sola con cada aplicación (cada test, cada petición).
 */
final class ErrorRecorder
{
    private const MAX_POR_VENTANA = 30;

    private const VENTANA_SEGUNDOS = 60;

    private int $anotadas = 0;

    private int $ventanaDesde = 0;

    private bool $grabando = false;

    /** Las columnas y tablas de la versión 2 que tienen que existir para escribir en ese modo. */
    private const COLUMNAS_V2 = [
        'status', 'resolved_at', 'resolved_by', 'service', 'route_name',
        'fingerprint_version', 'companies_count', 'users_count',
    ];

    /**
     * ¿Se aplicó ya la migración de los grupos multiempresa?
     *
     * Se decide con UNA consulta al catálogo por tabla (memoizada) y no columna a columna: en Vercel cada
     * petición es un proceso nuevo y el memo no sobrevive de una a otra.
     */
    public function enModoV2(): bool
    {
        return DbTable::existe('error_event_companies')
            && DbTable::existe('error_event_users')
            && DbTable::tieneColumnas('error_events', self::COLUMNAS_V2);
    }

    /**
     * Anota el error en el grupo que le corresponde (modo v2).
     *
     * @param  array<int, string>  $marcos
     */
    public function record(Throwable $e, array $marcos, ?string $url, ?int $companyId, ?int $userId): void
    {
        // Sin reentrada: si algo de aquí dentro volviera a reportar un error, no se anota a sí mismo.
        if ($this->grabando || ! $this->hayCupo()) {
            return;
        }

        $this->grabando = true;

        try {
            $this->grabar($e, $marcos, $url, $companyId, $userId);
        } finally {
            $this->grabando = false;
        }
    }

    /**
     * @param  array<int, string>  $marcos
     */
    private function grabar(Throwable $e, array $marcos, ?string $url, ?int $companyId, ?int $userId): void
    {
        $snapshot = ExceptionSnapshot::from($e);
        $ruta = $this->nombreDeRuta();
        $servicio = ServiceResolver::forSnapshot($snapshot);
        $huella = ErrorFingerprint::make($snapshot, $servicio, $ruta);
        $ahora = now();

        $fila = [
            'class' => $snapshot->class,
            // Ya sin credenciales y sin los valores de una consulta: ver `ExceptionSnapshot`.
            'message' => $snapshot->sampleMessage,
            'origin' => $snapshot->originLabel(),
            'frames' => implode(' <- ', $marcos),
            'url' => SecretRedactor::sanitizeUrl($url),
            'service' => $servicio,
            'last_seen_at' => $ahora,
        ];

        // «El último conocido»: solo se pisa si llega con valor (ver la cabecera).
        foreach (['route_name' => $ruta, 'company_id' => $companyId, 'user_id' => $userId] as $columna => $valor) {
            if ($valor !== null) {
                $fila[$columna] = $valor;
            }
        }

        $suma = $fila + [
            'hits' => DB::raw('hits + 1'),
            'updated_at' => $ahora,
            // Reaparece un error dado por resuelto: vuelve a estar activo.
            'status' => DB::raw("CASE WHEN status = 'resolved' THEN 'active' ELSE status END"),
            'resolved_at' => DB::raw("CASE WHEN status = 'resolved' THEN NULL ELSE resolved_at END"),
            'resolved_by' => DB::raw("CASE WHEN status = 'resolved' THEN NULL ELSE resolved_by END"),
        ];

        $sumadas = ErrorEvent::query()->where('fingerprint', $huella)->update($suma);

        if ($sumadas > 0) {
            $grupo = (int) ErrorEvent::query()->where('fingerprint', $huella)->value('id');
        } else {
            try {
                $grupo = (int) ErrorEvent::query()->insertGetId($fila + [
                    'fingerprint' => $huella,
                    'fingerprint_version' => ErrorFingerprint::VERSION,
                    'status' => 'active',
                    'hits' => 1,
                    'companies_count' => 0,
                    'users_count' => 0,
                    'first_seen_at' => $ahora,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            } catch (Throwable) {
                // Otra petición insertó la misma huella entre el update y este insert: se suma a la suya.
                ErrorEvent::query()->where('fingerprint', $huella)->update($suma);
                $grupo = (int) ErrorEvent::query()->where('fingerprint', $huella)->value('id');
            }
        }

        if ($grupo === 0) {
            return;
        }

        if ($companyId !== null) {
            $this->sumarHija(
                'error_event_companies',
                ['error_event_id' => $grupo, 'company_id' => $companyId],
                [],
                'companies_count',
                $grupo,
                $ahora,
            );
        }

        if ($userId !== null) {
            $this->sumarHija(
                'error_event_users',
                ['error_event_id' => $grupo, 'user_id' => $userId],
                $companyId !== null ? ['company_id' => $companyId] : [],
                'users_count',
                $grupo,
                $ahora,
            );
        }
    }

    /**
     * Suma una ocurrencia a la fila hija; si no existía, la crea y suma uno al contador del grupo.
     *
     * El contador del grupo solo sube cuando el INSERT tuvo éxito: si dos peticiones crean a la vez la
     * misma fila, la restricción única deja pasar una, y solo esa cuenta una empresa (o un usuario) más.
     *
     * @param  array<string, int>  $clave
     * @param  array<string, int>  $extra
     */
    private function sumarHija(string $tabla, array $clave, array $extra, string $contador, int $grupo, CarbonInterface $ahora): void
    {
        $sumar = ['hits' => DB::raw('hits + 1'), 'last_seen_at' => $ahora];

        if (DB::table($tabla)->where($clave)->update($sumar) > 0) {
            return;
        }

        try {
            DB::table($tabla)->insert($clave + $extra + [
                'hits' => 1,
                'first_seen_at' => $ahora,
                'last_seen_at' => $ahora,
            ]);

            DB::table('error_events')->where('id', $grupo)->increment($contador);
        } catch (Throwable) {
            DB::table($tabla)->where($clave)->update($sumar);
        }
    }

    private function nombreDeRuta(): ?string
    {
        try {
            $nombre = request()->route()?->getName();

            return is_string($nombre) && $nombre !== '' ? mb_substr($nombre, 0, 150) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function hayCupo(): bool
    {
        $ahora = time();

        if ($ahora - $this->ventanaDesde >= self::VENTANA_SEGUNDOS) {
            $this->ventanaDesde = $ahora;
            $this->anotadas = 0;
        }

        return ++$this->anotadas <= self::MAX_POR_VENTANA;
    }
}
