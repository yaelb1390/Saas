<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\SystemEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Endpoint de mantenimiento disparado por el cron de Vercel (no hay scheduler en serverless). Ejecuta
 * la purga de datos de pruebas vencidas. Se protege con un secreto compartido: Vercel Cron envía
 * `Authorization: Bearer <CRON_SECRET>` cuando el env `CRON_SECRET` está definido. Sin el secreto
 * correcto responde 403, así que la URL no es utilizable por terceros.
 */
final class TrialMaintenanceController extends Controller
{
    public function purgeTrials(Request $request): JsonResponse
    {
        $this->assertCron($request);

        return $this->ejecutar('trials:purge', 'Purga de pruebas caducadas');
    }

    public function remindExpiring(Request $request): JsonResponse
    {
        $this->assertCron($request);

        return $this->ejecutar('subscriptions:remind-expiring', 'Aviso de suscripciones por vencer');
    }

    /**
     * Corre la tarea y deja constancia de que corrió.
     *
     * El fallo se registra pero NO se propaga como 500: quien llama es un cron, y un 500 solo
     * consigue que lo reintente en bucle. Lo que hace falta es que quede escrito.
     */
    private function ejecutar(string $comando, string $titulo): JsonResponse
    {
        try {
            Artisan::call($comando);
            $salida = trim(Artisan::output());
        } catch (Throwable $e) {
            SystemEvent::registrar(
                type: 'task.failed',
                message: "{$titulo}: falló",
                contexto: ['comando' => $comando, 'motivo' => $e->getMessage()],
                level: SystemEvent::GRAVE,
            );

            return response()->json(['ok' => false], 200);
        }

        SystemEvent::registrar(
            type: 'task.run',
            message: "{$titulo}: ejecutada",
            contexto: ['comando' => $comando, 'salida' => mb_substr($salida, 0, 300)],
        );

        return response()->json(['ok' => true, 'output' => $salida]);
    }

    /**
     * Exige el secreto compartido (Vercel Cron manda `Authorization: Bearer <CRON_SECRET>`). Sin el
     * secreto correcto responde 403, así que la URL no es utilizable por terceros.
     */
    private function assertCron(Request $request): void
    {
        $secret = (string) config('services.cron.secret', '');
        $provided = (string) $request->bearerToken();

        if ($secret === '' || ! hash_equals($secret, $provided)) {
            SystemEvent::registrar(
                type: 'task.rejected',
                message: 'Llamada a una tarea programada sin el secreto correcto',
                contexto: ['ruta' => $request->path()],
                level: SystemEvent::AVISO,
            );

            abort(403);
        }
    }
}
