<?php

declare(strict_types=1);

namespace App\Modules\Core\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caché de lectura aislada por empresa e invalidada por evento, no por tiempo.
 *
 * Cada empresa tiene un entero de "versión" (`company:{id}:cache-version`) que forma parte de todas
 * sus claves. Mientras nada cambie, la versión es estable y las lecturas repetidas salen de caché
 * sin tocar la base de datos. Cuando ocurre una mutación del dominio (una venta, una factura, un
 * cambio de estado de entrega...), un listener llama a {@see flush()}, que incrementa la versión:
 * las claves con la versión anterior quedan inalcanzables y expiran solas por el TTL de seguridad.
 *
 * Se prefiere este esquema de versión a los cache tags de Redis porque es portable (funciona con el
 * store `array` de los tests) y la invalidación es O(1). El precio es que es de grano grueso: un
 * evento invalida TODOS los recursos cacheados de esa empresa. Es un intercambio aceptado: el
 * conjunto de recursos por empresa es pequeño y la simplicidad prima.
 *
 * El TTL de seguridad no es el mecanismo de frescura (lo es la invalidación por evento); es solo la
 * red que autocorrige la caché si algún evento no llegara a dispararse.
 */
final class CompanyCache
{
    /** Segundos que sobrevive una entrada si ningún evento la invalida antes (24 h). */
    private const SAFETY_TTL = 86400;

    /**
     * Devuelve el recurso cacheado de la empresa, calculándolo con $callback solo si no está en
     * caché para la versión vigente.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function remember(int $companyId, string $resource, Closure $callback): mixed
    {
        return Cache::remember($this->key($companyId, $resource), self::SAFETY_TTL, $callback);
    }

    /**
     * Invalida toda la caché de la empresa incrementando su versión. Idempotente y barato: una sola
     * operación deja obsoletos todos los recursos cacheados de esa empresa.
     */
    public function flush(int $companyId): void
    {
        Cache::increment($this->versionKey($companyId));
    }

    /** Clave versionada de un recurso de la empresa. */
    public function key(int $companyId, string $resource): string
    {
        return "company:{$companyId}:v{$this->version($companyId)}:{$resource}";
    }

    /** Versión de caché vigente de la empresa (0 mientras nunca se haya invalidado). */
    public function version(int $companyId): int
    {
        return (int) Cache::get($this->versionKey($companyId), 0);
    }

    private function versionKey(int $companyId): string
    {
        return "company:{$companyId}:cache-version";
    }
}
