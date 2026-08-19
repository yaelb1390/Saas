<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Un fallo, agrupado por huella.
 *
 * NO lleva `BelongsToCompany`: la lee el operador de la plataforma para ver todas las empresas a la
 * vez, y el ámbito la dejaría enseñando solo las de la empresa que tenga abierta.
 */
final class ErrorEvent extends Model
{
    /** Las claves que suelen viajar dentro del mensaje de error de una API. */
    private const SECRETOS = '/(AIza|sk-|sk_|xkeysib-|xsmtpsib-|polar_oat_|Bearer\s+)[A-Za-z0-9_\-]{8,}/';

    protected $fillable = [
        'fingerprint', 'class', 'message', 'origin', 'frames',
        'url', 'company_id', 'user_id', 'hits', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
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
     * Anota un fallo, sumando al grupo si ya se había visto.
     *
     * @param  array<int, string>  $marcos
     */
    public static function anotar(Throwable $e, array $marcos, ?string $url, ?int $companyId, ?int $userId): void
    {
        $origen = basename($e->getFile()).':'.$e->getLine();
        $huella = substr(sha1($e::class.'|'.$origen), 0, 40);

        $fila = [
            'class' => $e::class,
            // El mensaje se limpia ANTES de guardarlo: un error de una API suele traer la
            // credencial dentro, y guardarla aquí sería filtrarla a una pantalla y a los respaldos.
            'message' => self::limpiar(mb_substr($e->getMessage(), 0, 400)),
            'origin' => $origen,
            'frames' => implode(' <- ', $marcos),
            'url' => $url === null ? null : mb_substr($url, 0, 500),
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
        return preg_replace(self::SECRETOS, '***', $texto) ?? $texto;
    }
}
