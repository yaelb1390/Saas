<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;

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

        Artisan::call('trials:purge');

        return response()->json([
            'ok' => true,
            'output' => trim(Artisan::output()),
        ]);
    }

    public function remindExpiring(Request $request): JsonResponse
    {
        $this->assertCron($request);

        Artisan::call('subscriptions:remind-expiring');

        return response()->json([
            'ok' => true,
            'output' => trim(Artisan::output()),
        ]);
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
            abort(403);
        }
    }
}
