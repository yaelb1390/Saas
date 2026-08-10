<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Product;

/**
 * Genera el SKU cuando quien da de alta un producto no escribe uno.
 *
 * Teclear un código a mano en cada alta es trabajo sin valor y una fuente de duplicados; el sistema
 * ya lo hace solo para las ventas (`V-000001`) y aquí se sigue el mismo formato.
 */
final class SkuGenerator
{
    private const PREFIX = 'PROD-';

    private const PAD = 6;

    /** Reintentos ante una carrera: dos altas simultáneas podrían calcular el mismo número. */
    private const ATTEMPTS = 5;

    /**
     * Siguiente SKU libre de la empresa.
     *
     * Se cuenta desde el MAYOR existente y no desde el número de productos, y se miran también los
     * borrados en suave: el índice único de la base sí ve esas filas (por eso el formulario valida
     * el SKU sin `withoutTrashed`), así que reutilizar su número reventaría el INSERT.
     */
    public function next(int $companyId): string
    {
        $ultimo = $this->highestNumber($companyId);

        for ($intento = 0; $intento < self::ATTEMPTS; $intento++) {
            $candidato = self::PREFIX.str_pad((string) (++$ultimo), self::PAD, '0', STR_PAD_LEFT);

            if (! $this->exists($companyId, $candidato)) {
                return $candidato;
            }
        }

        // Agotados los intentos (carrera persistente o numeración muy fragmentada): se cae a un
        // sufijo aleatorio antes que devolver un código ya usado.
        return self::PREFIX.mb_strtoupper(bin2hex(random_bytes(3)));
    }

    /** Mayor número ya emitido con este prefijo, o 0 si no hay ninguno. */
    private function highestNumber(int $companyId): int
    {
        $skus = Product::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('sku', 'like', self::PREFIX.'%')
            ->pluck('sku');

        $mayor = 0;

        foreach ($skus as $sku) {
            $sufijo = mb_substr((string) $sku, mb_strlen(self::PREFIX));

            // Solo cuentan los que siguen el formato: un «PROD-CONO» escrito a mano no debe
            // desplazar la numeración ni romper la comparación.
            if (ctype_digit($sufijo)) {
                $mayor = max($mayor, (int) $sufijo);
            }
        }

        return $mayor;
    }

    private function exists(int $companyId, string $sku): bool
    {
        return Product::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->exists();
    }
}
