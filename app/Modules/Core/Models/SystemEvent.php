<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
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

    /**
     * Las claves que suelen viajar dentro del detalle de un fallo de API.
     *
     * Es la misma expresión que usa `ErrorEvent`, y está repetida a propósito: son dos tablas que se
     * escriben en momentos distintos y por caminos distintos, y hacer que una dependa de la otra
     * para poder limpiar sería acoplarlas por un `preg_replace`.
     */
    private const SECRETOS = '/(AIza|sk-|sk_|xkeysib-|xsmtpsib-|polar_oat_|Bearer\s+)[A-Za-z0-9_\-]{8,}/';

    /**
     * Si la tabla existe. Se comprueba UNA vez por proceso.
     *
     * Mismo motivo que en `Audit`: aquí las migraciones se aplican a mano y el despliegue no las
     * corre, así que el código llega siempre antes que el cambio en la base. Sin esto, el hueco
     * entre las dos cosas no sería «el registro aún no se ve»: sería que NADIE PUEDE INICIAR SESIÓN,
     * porque el intento de anotar la entrada reventaría dentro del propio inicio de sesión.
     */
    private static ?bool $existe = null;

    protected $fillable = [
        'company_id', 'user_id', 'type', 'level', 'message', 'context', 'ip', 'user_agent',
    ];

    /**
     * Anota un suceso. NUNCA lanza.
     *
     * Es la regla que no se negocia: esto se llama desde dentro del inicio de sesión, de un webhook
     * y de un borrado de empresa. Si fallara al escribir, se llevaría por delante la operación que
     * estaba registrando, y un registro que rompe lo que vigila es peor que no tener registro.
     *
     * @param  array<string, mixed>  $contexto
     */
    public static function registrar(
        string $type,
        string $message,
        array $contexto = [],
        string $level = self::INFO,
        ?int $companyId = null,
        ?int $userId = null,
    ): void {
        try {
            if (! self::hayTabla()) {
                return;
            }

            $peticion = request();

            self::create([
                // La empresa que se pase gana; si no, la activa. En un intento de acceso fallido no
                // hay ninguna de las dos, y por eso la columna admite nulo.
                'company_id' => $companyId ?? self::empresaActiva(),
                'user_id' => $userId ?? auth()->id(),
                'type' => $type,
                'level' => $level,
                'message' => mb_substr($message, 0, 300),
                'context' => self::limpiar($contexto),
                'ip' => $peticion?->ip(),
                'user_agent' => mb_substr((string) $peticion?->userAgent(), 0, 255) ?: null,
            ]);
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
        self::$existe = null;
    }

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    private static function hayTabla(): bool
    {
        if (self::$existe === null) {
            try {
                self::$existe = Schema::hasTable('system_events');
            } catch (Throwable) {
                self::$existe = false;
            }
        }

        return self::$existe;
    }

    private static function empresaActiva(): ?int
    {
        try {
            $actual = app(CurrentCompany::class);

            return $actual->has() ? $actual->id() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tacha las credenciales del detalle antes de guardarlo.
     *
     * Un fallo de una API trae la clave dentro más veces de las que parece, y guardarla aquí sería
     * filtrarla a una pantalla y, de paso, a todas las copias de seguridad.
     *
     * @param  array<string, mixed>  $contexto
     * @return array<string, mixed>
     */
    private static function limpiar(array $contexto): array
    {
        array_walk_recursive($contexto, static function (mixed &$valor): void {
            if (is_string($valor)) {
                $valor = preg_replace(self::SECRETOS, '***', mb_substr($valor, 0, 500)) ?? '***';
            }
        });

        return $contexto;
    }
}
