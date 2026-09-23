<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Modules\Core\Monitoring\Errors\ErrorRecorder;
use App\Modules\Core\Monitoring\Errors\ExceptionSnapshot;
use App\Modules\Core\Support\SecretRedactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Un fallo, agrupado por huella.
 *
 * NO lleva `BelongsToCompany`: la lee el operador de la plataforma para ver todas las empresas a la
 * vez, y el ámbito la dejaría enseñando solo las de la empresa que tenga abierta.
 *
 * Una fila es un GRUPO de errores iguales, no una ocurrencia. Cuántas veces ocurrió (`hits`), en cuántas
 * empresas (`companies_count`) y a cuántos usuarios (`users_count`) afectó vive aquí; el desglose por
 * empresa y por usuario, en `error_event_companies` y `error_event_users`.
 *
 * `company_id` y `user_id` son «el último conocido», no el desglose: el grupo puede afectar a muchas
 * empresas. Se conservan por compatibilidad con los grupos anteriores.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $class
 * @property string $message
 * @property int $hits
 * @property string $status
 * @property string|null $service
 * @property int $fingerprint_version
 * @property int $companies_count
 * @property int $users_count
 * @property int $recent_hits
 */
final class ErrorEvent extends Model
{
    public const ACTIVO = 'active';

    public const RESUELTO = 'resolved';

    public const IGNORADO = 'ignored';

    protected $fillable = [
        'fingerprint', 'class', 'message', 'origin', 'frames',
        'url', 'company_id', 'user_id', 'hits', 'first_seen_at', 'last_seen_at',
        'status', 'resolved_at', 'resolved_by', 'service', 'route_name',
        'fingerprint_version', 'companies_count', 'users_count', 'recent_hits',
    ];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'companies_count' => 'integer',
            'users_count' => 'integer',
            'fingerprint_version' => 'integer',
            'recent_hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * El desglose por empresa: cuántas veces sufrió cada una este error.
     *
     * @return HasMany<ErrorEventCompany, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(ErrorEventCompany::class);
    }

    /**
     * Los usuarios a los que afectó.
     *
     * @return HasMany<ErrorEventUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(ErrorEventUser::class);
    }

    /**
     * Los que siguen pidiendo atención: ni resueltos ni ignorados.
     *
     * @param  Builder<ErrorEvent>  $consulta
     * @return Builder<ErrorEvent>
     */
    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('status', self::ACTIVO);
    }

    /** Un grupo anterior al agrupado por empresa: su desglose es una aproximación, no un dato. */
    public function esHistorico(): bool
    {
        return (int) $this->fingerprint_version < 2;
    }

    /**
     * Veces que ocurrió sin empresa conocida (la plataforma, la consola, un visitante sin sesión).
     *
     * No se guarda: es lo que sobra de restar al total lo que se atribuyó a alguna empresa. Guardarlo
     * sería un tercer contador que mantener al día en cada repetición.
     */
    public function hitsSinEmpresa(): int
    {
        return max(0, $this->hits - (int) $this->companies()->sum('hits'));
    }

    /**
     * Anota un fallo, sumando al grupo si ya se había visto.
     *
     * Con la migración de los grupos multiempresa aplicada, delega en `ErrorRecorder` (huella v2, desglose
     * por empresa y usuario). Sin ella —el código sale antes que la migración, que aquí se aplica a mano—
     * sigue como hasta ahora, para no dejar de guardar errores en ese hueco.
     *
     * @param  array<int, string>  $marcos
     */
    public static function anotar(Throwable $e, array $marcos, ?string $url, ?int $companyId, ?int $userId): void
    {
        $registrador = app(ErrorRecorder::class);

        if ($registrador->enModoV2()) {
            $registrador->record($e, $marcos, $url, $companyId, $userId);

            return;
        }

        self::anotarSinDesglose($e, $marcos, $url, $companyId, $userId);
    }

    /**
     * El agrupado de antes de los grupos multiempresa: una huella por clase y sitio del `throw`, y la
     * empresa como «la última vista». Se conserva tal cual mientras falte la migración; lo único que
     * cambia es que el mensaje y la dirección ya no se guardan con credenciales.
     *
     * @param  array<int, string>  $marcos
     */
    private static function anotarSinDesglose(Throwable $e, array $marcos, ?string $url, ?int $companyId, ?int $userId): void
    {
        $origen = basename($e->getFile()).':'.$e->getLine();
        $huella = substr(sha1($e::class.'|'.$origen), 0, 40);

        try {
            // Sin la sentencia con sus valores: ver `ExceptionSnapshot`.
            $mensaje = ExceptionSnapshot::from($e)->sampleMessage;
        } catch (Throwable) {
            $mensaje = self::limpiar(mb_substr($e->getMessage(), 0, 400));
        }

        $fila = [
            'class' => $e::class,
            // El mensaje se limpia ANTES de guardarlo: un error de una API suele traer la
            // credencial dentro, y guardarla aquí sería filtrarla a una pantalla y a los respaldos.
            'message' => $mensaje,
            'origin' => $origen,
            'frames' => implode(' <- ', $marcos),
            'url' => SecretRedactor::sanitizeUrl($url),
            'company_id' => $companyId,
            'user_id' => $userId,
            'last_seen_at' => now(),
        ];

        /*
         * Primero se intenta SUMAR al grupo que ya exista, y solo si no había ninguno se inserta.
         * Al revés —leer, decidir, escribir— dos peticiones fallando a la vez crearían dos filas
         * con la misma huella, la única lo rechazaria, y el registro de errores se convertiría en
         * otro error.
         */
        $sumadas = self::query()
            ->where('fingerprint', $huella)
            ->update($fila + ['hits' => DB::raw('hits + 1'), 'updated_at' => now()]);

        if ($sumadas > 0) {
            return;
        }

        try {
            self::query()->insert($fila + [
                'fingerprint' => $huella,
                'hits' => 1,
                'first_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Otra petición insertó la misma huella entre el update y este insert. Se suma a la suya.
            self::query()
                ->where('fingerprint', $huella)
                ->update($fila + ['hits' => DB::raw('hits + 1'), 'updated_at' => now()]);
        }
    }

    /** Tacha lo que parezca una credencial. */
    public static function limpiar(string $texto): string
    {
        return SecretRedactor::redact($texto);
    }
}
