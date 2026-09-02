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
 * Las tareas de mantenimiento, disparadas por el cron de Vercel (en serverless no hay scheduler).
 *
 * Purga las pruebas caducadas, avisa de las suscripciones por vencer, poda la auditoría y vacía la
 * cola. Todas se protegen con el mismo secreto compartido: Vercel Cron envía
 * `Authorization: Bearer <CRON_SECRET>` cuando el env `CRON_SECRET` está definido. Sin el secreto
 * correcto responde 403, así que las direcciones no son utilizables por terceros.
 *
 * Son direcciones que EJECUTAN trabajo real sin sesión de por medio, así que ese secreto es lo único
 * que las separa de cualquiera que las encuentre. Si algún día se añade otra tarea aquí, lo primero
 * de su método tiene que seguir siendo `assertCron()`.
 */
final class TrialMaintenanceController extends Controller
{
    /**
     * Las dos tareas que tienen efectos hacia fuera admiten `?simular=1`.
     *
     * POR QUÉ. Una BORRA datos de negocio y la otra MANDA CORREOS a clientes reales, y ninguna de las
     * dos había corrido nunca en producción: la primera vez se encuentran con todo lo acumulado desde
     * el principio. En serverless no hay consola donde mirar antes cuánto es eso, así que la única
     * forma de verlo es pidiéndoselo a la propia dirección.
     *
     * El simulacro va detrás del MISMO secreto que la tarea de verdad: enseña nombres de empresas y
     * correos de clientes, así que no es menos delicado que ejecutarla.
     */
    public function purgeTrials(Request $request): JsonResponse
    {
        $this->assertCron($request);

        return $this->ejecutar('trials:purge', 'Purga de pruebas caducadas', $this->simulacro($request));
    }

    public function remindExpiring(Request $request): JsonResponse
    {
        $this->assertCron($request);

        return $this->ejecutar('subscriptions:remind-expiring', 'Aviso de suscripciones por vencer', $this->simulacro($request));
    }

    /**
     * `--simular` si lo pide la dirección.
     *
     * Vercel Cron llama sin parámetros, así que la tarea programada hace SIEMPRE el trabajo de
     * verdad; el simulacro solo sale si alguien lo pide a mano.
     *
     * @return array<string, mixed>
     */
    private function simulacro(Request $request): array
    {
        return $request->boolean('simular') ? ['--simular' => true] : [];
    }

    /**
     * Poda la auditoría y el registro de sucesos.
     *
     * Son las dos tablas que crecen sin que nadie las mire —una fila por cada cambio de cada modelo
     * auditado— y hasta ahora no las borraba nada. En una base con 500 MB de cupo, eso no es un
     * detalle de mantenimiento: es lo que un día llena el disco y para el negocio.
     */
    public function purgeRecords(Request $request): JsonResponse
    {
        $this->assertCron($request);

        return $this->ejecutar('registros:purgar', 'Poda de auditoría y sucesos');
    }

    /**
     * Vacía la cola: coge los trabajos pendientes, los ejecuta y vuelve.
     *
     * POR QUÉ HACE FALTA. En serverless no hay un proceso que viva entre peticiones, así que no hay
     * dónde tener un trabajador escuchando. Sin esta dirección, la única forma de que se envíe un
     * WhatsApp es `QUEUE_CONNECTION=sync`, o sea ejecutarlo DENTRO de la petición del cliente,
     * haciéndole esperar a que contesten OpenAI y Evolution API una detrás de otra.
     *
     * `--stop-when-empty` es lo que la hace apta para serverless: en cuanto no queda trabajo, vuelve.
     * No se queda escuchando —no podría— ni gasta el tiempo de la función esperando.
     *
     * El tope de tiempo se queda MUY por debajo del de la función a propósito. Si la plataforma corta
     * a mitad de un trabajo, ese trabajo se queda reservado y no se reintenta hasta que vence su
     * plazo: parece perdido durante minutos. Terminando por nuestra cuenta antes, se sale siempre por
     * la puerta y no por la ventana.
     */
    public function drainQueue(Request $request): JsonResponse
    {
        $this->assertCron($request);

        $segundos = max(5, (int) config('queue.drenaje.segundos', 25));

        return $this->ejecutar('queue:work', 'Drenaje de la cola', [
            '--stop-when-empty' => true,
            '--max-time' => $segundos,
            // Menos que el tope de la tanda: un trabajo que se cuelga no puede comerse el turno
            // entero y dejar a los demás sin ejecutarse.
            '--timeout' => max(5, $segundos - 5),
            '--tries' => (int) config('queue.drenaje.intentos', 3),
        ]);
    }

    /**
     * Corre la tarea y deja constancia de que corrió.
     *
     * El fallo se registra pero NO se propaga como 500: quien llama es un cron, y un 500 solo
     * consigue que lo reintente en bucle. Lo que hace falta es que quede escrito.
     *
     * @param  array<string, mixed>  $parametros
     */
    private function ejecutar(string $comando, string $titulo, array $parametros = []): JsonResponse
    {
        try {
            Artisan::call($comando, $parametros);
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
