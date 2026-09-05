<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\SaleItem;
use Illuminate\Support\Facades\Cache;

/**
 * Los artículos que más se venden, para ofrecerlos de un toque en el mostrador.
 *
 * POR QUÉ SE CALCULAN Y NO SE CONFIGURAN. La alternativa era una lista que alguien tuviera que
 * mantener a mano, y esas listas envejecen: se rellenan el primer día y nadie vuelve a tocarlas. Lo
 * que de verdad se despacha a diario lo dicen las ventas, y se adapta solo a cada negocio —una
 * cafetería y una ferretería no comparten nada— sin pedirle a nadie que configure nada.
 *
 * VIVE EN VENTAS Y NO EN INVENTARIO a propósito. Consulta líneas de venta; si viviera en Inventario,
 * Inventario dependería de Ventas y se invertiría la dirección de las dependencias. Es el mismo
 * motivo por el que `TaxCalculator` vive en Core.
 */
final class MasVendidos
{
    /** Cuántos días atrás se mira. Un mes cubre la temporada sin arrastrar lo de hace medio año. */
    private const DIAS = 30;

    /**
     * Cuánto se guarda el resultado.
     *
     * Media hora: esto se pinta en CADA carga de la pantalla y es una consulta con agrupación sobre
     * la tabla más grande del sistema. Que un artículo entre o salga de la lista con media hora de
     * retraso no le importa a nadie; que la pantalla tarde en abrir, sí.
     */
    private const MINUTOS = 30;

    /**
     * Los identificadores de los artículos más vendidos de la empresa activa.
     *
     * @return array<int, int>
     */
    public function ids(int $cuantos = 8): array
    {
        $empresa = app(CurrentCompany::class)->id();

        if ($empresa === null) {
            return [];
        }

        return Cache::remember(
            "mas-vendidos:{$empresa}:{$cuantos}",
            now()->addMinutes(self::MINUTOS),
            fn (): array => $this->calcular($cuantos),
        );
    }

    /**
     * @return array<int, int>
     */
    private function calcular(int $cuantos): array
    {
        return SaleItem::query()
            /*
             * Se cuenta por la VENTA, no por la línea suelta: una venta anulada no debería seguir
             * empujando su artículo a la lista de lo más vendido. `whereHas` filtra por el estado de
             * la cabecera, y el borrado lógico de la venta ya lo excluye por su cuenta.
             */
            ->whereHas('sale', fn ($q) => $q
                ->where('status', SaleStatus::Completed)
                ->where('completed_at', '>=', now()->subDays(self::DIAS)))
            ->groupBy('product_id')
            /*
             * Por CANTIDAD vendida y no por número de tickets: en un mostrador se despachan diez
             * tornillos de una vez y un filtro por venta, y lo que hay que tener a mano es lo que más
             * sale por la puerta.
             */
            ->orderByRaw('sum(quantity) desc')
            ->limit($cuantos)
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
