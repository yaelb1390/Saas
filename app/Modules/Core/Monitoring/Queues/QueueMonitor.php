<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Queues;

use App\Modules\WhatsApp\Enums\MessageStatus;
use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Cuántos trabajos hay pendientes, reservados o retrasados, y desde hace cuánto — según el driver
 * que la instalación tenga puesto, que cambia lo que se puede preguntar:
 *
 *  · `database` (producción, hoy): una consulta agrupada sobre `jobs`. Es la única con antigüedad
 *    real: sabe desde CUÁNDO espera el más viejo.
 *  · `redis` (local): `pendingSize`/`delayedSize`/`reservedSize`, que Redis ya lleva contadas.
 *  · `sync` o cualquier otro: NO HAY cola que preguntar —cada trabajo corre dentro de la petición
 *    que lo despachó, y ya terminó cuando esta clase se ejecuta—. En vez de fingir un cero que
 *    parecería «todo va bien», se usa un proxy: los mensajes de WhatsApp `pending` desde hace más de
 *    15 minutos, que es justo la señal de que algo se quedó a medias sin que nadie lo esté sacando
 *    de la cola.
 */
final class QueueMonitor
{
    private const MINUTOS_ATASCADO = 15;

    /**
     * @return array{
     *     driver: string, aplica: bool, pendientes: int, reservados: int, retrasados: int,
     *     antiguedad_segundos: int|null, colas: Collection<int, array{queue: string, pendientes: int}>,
     * }
     */
    public function snapshot(): array
    {
        return match ((string) config('queue.default')) {
            'database' => $this->desdeBaseDeDatos(),
            'redis' => $this->desdeRedis(),
            default => $this->sinCola(),
        };
    }

    /**
     * Los últimos trabajos fallidos, SIN cargar `payload` ni `exception` completos: son columnas
     * largas —el cuerpo serializado del trabajo, la traza entera— y aquí solo hace falta saber cuál
     * falló, en qué cola y cuándo, no reconstruirlo.
     *
     * @return Collection<int, object>
     */
    public function fallidosRecientes(int $limite = 20): Collection
    {
        return DB::table('failed_jobs')
            ->select(['id', 'uuid', 'connection', 'queue', 'failed_at'])
            ->latest('failed_at')
            ->limit($limite)
            ->get();
    }

    /** Cuántos fallaron, por cola. */
    public function fallidosPorCola(): Collection
    {
        return DB::table('failed_jobs')
            ->selectRaw('queue, count(*) as total')
            ->groupBy('queue')
            ->orderByDesc('total')
            ->get();
    }

    private function desdeBaseDeDatos(): array
    {
        $ahora = time();

        $filas = DB::table('jobs')
            ->selectRaw('queue, reserved_at, available_at, created_at')
            ->get();

        $pendientes = $filas->filter(fn ($f): bool => $f->reserved_at === null && (int) $f->available_at <= $ahora);
        $reservados = $filas->filter(fn ($f): bool => $f->reserved_at !== null);
        $retrasados = $filas->filter(fn ($f): bool => $f->reserved_at === null && (int) $f->available_at > $ahora);

        $masViejo = $pendientes->min('created_at');

        return [
            'driver' => 'database',
            'aplica' => true,
            'pendientes' => $pendientes->count(),
            'reservados' => $reservados->count(),
            'retrasados' => $retrasados->count(),
            'antiguedad_segundos' => $masViejo !== null ? max(0, $ahora - (int) $masViejo) : null,
            'colas' => $pendientes->groupBy('queue')
                ->map(fn (Collection $c, string $cola): array => ['queue' => $cola, 'pendientes' => $c->count()])
                ->values(),
        ];
    }

    private function desdeRedis(): array
    {
        $cola = (string) config('queue.connections.redis.queue', 'default');

        try {
            $conexion = Queue::connection('redis');

            // `pendingSize`/`delayedSize`/`reservedSize` son propias de `RedisQueue`, no del
            // contrato `Queue`: de ahí el `method_exists`, no un `instanceof` contra una clase
            // concreta que un driver a medida podría no extender.
            if (! method_exists($conexion, 'pendingSize')) {
                return $this->sinCola();
            }

            $pendientes = (int) $conexion->pendingSize($cola);
            $retrasados = (int) $conexion->delayedSize($cola);
            $reservados = (int) $conexion->reservedSize($cola);
        } catch (Throwable) {
            return $this->sinCola();
        }

        return [
            'driver' => 'redis',
            'aplica' => true,
            'pendientes' => $pendientes,
            'reservados' => $reservados,
            'retrasados' => $retrasados,
            // Redis no dice desde cuándo espera el más viejo sin leer la cola entera: no se paga
            // ese coste solo por una cifra de la pantalla.
            'antiguedad_segundos' => null,
            'colas' => collect([['queue' => $cola, 'pendientes' => $pendientes]]),
        ];
    }

    private function sinCola(): array
    {
        $atascados = WaMessage::query()->withoutGlobalScopes()
            ->where('status', MessageStatus::Pending)
            ->where('created_at', '<', now()->subMinutes(self::MINUTOS_ATASCADO))
            ->count();

        return [
            'driver' => (string) config('queue.default'),
            'aplica' => false,
            'pendientes' => $atascados,
            'reservados' => 0,
            'retrasados' => 0,
            'antiguedad_segundos' => null,
            'colas' => collect(),
        ];
    }
}
