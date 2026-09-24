<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Monitoring\Health\HealthCheckRunner;
use App\Modules\Core\Monitoring\Health\HealthRegistry;
use App\Modules\Core\Monitoring\Health\HealthStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comprueba UN servicio, para el botón «Comprobar ahora» y para el `fetch` que dispara el panel solo
 * en los servicios vencidos (más de 5 minutos sin comprobarse).
 *
 * `throttle:30,1` en la ruta: es una puerta para llamar a servicios EXTERNOS a petición de quien
 * mire la pantalla, y aunque solo la atraviese el operador, no debe poder usarse en ráfaga —cada
 * llamada le cuesta una petición real a Evolution, a la IA o a Polar—.
 */
final class MonitoringHealthController extends Controller
{
    public function check(string $servicio, HealthRegistry $registro, HealthCheckRunner $runner): JsonResponse
    {
        if ($registro->porClave($servicio) === null) {
            return response()->json(['ok' => false, 'motivo' => 'Servicio desconocido'], Response::HTTP_NOT_FOUND);
        }

        $resultados = $runner->comprobar($servicio, forzar: true, trigger: HealthStore::TRIGGER_PANEL);
        $resultado = $resultados->get($servicio);

        if ($resultado === null) {
            // No debería pasar con `forzar: true`, salvo que otra petición tuviera el candado en
            // ESE instante: se responde con lo último que ya hay guardado, no con un error.
            return response()->json(['ok' => true, 'repetido' => true]);
        }

        return response()->json([
            'ok' => true,
            'servicio' => $servicio,
            'estado' => $resultado->status,
            'disponible' => $resultado->available,
            'latencia_ms' => $resultado->latencyMs,
            'mensaje' => $resultado->message,
        ]);
    }
}
