<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Models\User;
use App\Modules\Core\Monitoring\Errors\ServiceResolver;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Support\SecretRedactor;
use App\Modules\Core\Support\TenantAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * Un suceso del sistema que no deja rastro en ninguna otra parte.
 *
 * NO lleva `BelongsToCompany`, igual que `Audit` y `ErrorEvent`: lo lee el operador de la plataforma
 * para ver TODAS las empresas a la vez, y el ámbito lo dejaría enseñando solo las de la que tenga
 * abierta. Con una sola empresa de prueba eso no se nota nunca.
 *
 * @property string $type
 * @property string $level
 * @property string|null $service
 * @property array<string, mixed>|null $context
 */
final class SystemEvent extends Model
{
    public const UPDATED_AT = null;

    /** Se mira de reojo: pasó algo y está bien que pasara. */
    public const INFO = 'info';

    /** Algo falló pero el sistema siguió: un servicio externo caído, un intento fallido. */
    public const AVISO = 'warning';

    /** Alguien tiene que mirarlo hoy: un borrado, un bloqueo, un ataque por fuerza bruta. */
    public const GRAVE = 'critical';

    protected $fillable = [
        'company_id', 'user_id', 'type', 'level', 'service', 'message', 'context', 'ip', 'user_agent',
    ];

    /**
     * Anota un suceso. NUNCA lanza.
     *
     * Es la regla que no se negocia: esto se llama desde dentro del inicio de sesión, de un webhook
     * y de un borrado de empresa. Si fallara al escribir, se llevaría por delante la operación que
     * estaba registrando, y un registro que rompe lo que vigila es peor que no tener registro.
     *
     * Aquí las migraciones se aplican a mano y el despliegue no las corre, así que el código llega
     * siempre antes que el cambio en la base. Sin la tabla, no se escribe nada; sin la columna nueva
     * (`service`), se escribe lo de siempre. Sin esto, el hueco entre las dos cosas no sería «el
     * registro aún no se ve»: sería que NADIE PUEDE INICIAR SESIÓN, porque el intento de anotar la
     * entrada reventaría dentro del propio inicio de sesión.
     *
     * @param  array<string, mixed>  $contexto
     * @param  string|null  $service  De qué servicio o integración habla; si no se dice, se deduce del
     *                                tipo y del mensaje (y queda vacío si no se puede saber).
     */
    public static function registrar(
        string $type,
        string $message,
        array $contexto = [],
        string $level = self::INFO,
        ?int $companyId = null,
        ?int $userId = null,
        ?string $service = null,
    ): void {
        try {
            $columnas = DbTable::columnas('system_events');

            if ($columnas === []) {
                return;
            }

            $peticion = request();

            $datos = [
                // La empresa que se pase gana; si no, la que corresponda (ver `TenantAttribution`). En un
                // intento de acceso fallido no hay ninguna, y por eso la columna admite nulo.
                'company_id' => $companyId ?? TenantAttribution::companyId(),
                'user_id' => $userId ?? auth()->id(),
                'type' => $type,
                'level' => $level,
                'message' => mb_substr($message, 0, 300),
                'context' => self::limpiar($contexto),
                'ip' => $peticion?->ip(),
                'user_agent' => mb_substr((string) $peticion?->userAgent(), 0, 255) ?: null,
            ];

            if (in_array('service', $columnas, true)) {
                $datos['service'] = $service ?? ServiceResolver::forEvent($type, $message);
            }

            self::create($datos);
        } catch (Throwable) {
            // A propósito en silencio. Reportarlo llamaría al manejador de errores, que a su vez
            // escribe en la base: si la base es el problema, sería un bucle.
        }
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Para los tests, que comparten proceso y necesitan volver a preguntar. */
    public static function olvidarSiHayTabla(): void
    {
        DbTable::olvidar();
    }

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    /**
     * Tacha las credenciales del detalle antes de guardarlo.
     *
     * Un fallo de una API trae la clave dentro más veces de las que parece, y guardarla aquí sería
     * filtrarla a una pantalla y, de paso, a todas las copias de seguridad. Lo hace `SecretRedactor`,
     * que es el mismo que usa `ErrorEvent`: antes había una copia de la expresión en cada modelo.
     *
     * @param  array<string, mixed>  $contexto
     * @return array<array-key, mixed>
     */
    private static function limpiar(array $contexto): array
    {
        return SecretRedactor::redactArray($contexto);
    }
}
