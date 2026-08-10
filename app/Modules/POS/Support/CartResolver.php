<?php

declare(strict_types=1);

namespace App\Modules\POS\Support;

use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\OptionResolver;
use App\Modules\Sales\DTOs\SaleLineData;

/**
 * Convierte el carrito que envía el navegador en líneas de venta de confianza.
 *
 * Aquí vive la regla de seguridad del punto de venta: **el precio nunca llega del cliente**. Del
 * carrito solo se aceptan el producto, la cantidad y los datos accesorios; el importe se relee del
 * catálogo en el servidor. Sin esto, cualquiera podría editar el JSON del formulario y fijarse el
 * precio que quisiera.
 *
 * Se comparte entre el POS de mostrador y el POS táctil: una sola implementación de la regla, en vez
 * de dos copias que puedan divergir.
 */
final class CartResolver
{
    public function __construct(private readonly OptionResolver $options) {}

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<int, SaleLineData>
     */
    public function toLines(array $cart): array
    {
        if ($cart === []) {
            return [];
        }

        // Empleados válidos de la empresa activa, en una sola consulta: valida el «atiende» de cada
        // línea sin volver a la base por cada una.
        $validEmployees = Employee::query()->pluck('id')->all();

        // Los productos del carrito, en una consulta: evita el N+1 de buscarlos uno a uno.
        $ids = array_values(array_filter(array_map(
            static fn (array $item): int => (int) ($item['id'] ?? 0),
            $cart,
        )));

        // `optionGroups` se precarga porque el resolvedor de opciones consulta a qué grupos pertenece
        // cada producto; sin esto sería una consulta por línea del ticket.
        $products = Product::query()->whereKey($ids)->with('optionGroups')->get()->keyBy('id');

        $lines = [];

        foreach ($cart as $item) {
            $product = $products->get((int) ($item['id'] ?? 0));

            if ($product === null) {
                continue;
            }

            // Tamaños, sabores y extras: se verifican contra los grupos del propio producto y su
            // recargo se lee de la base, nunca del navegador.
            $options = $this->options->resolve($product, (array) ($item['options'] ?? []));

            // Cantidad y descuento se sanean: cantidad > 0 y descuento nunca negativo.
            $employeeId = (int) ($item['employee_id'] ?? 0);

            $lines[] = new SaleLineData(
                productId: $product->id,
                quantity: (string) max(0.001, (float) ($item['qty'] ?? 1)),
                unitPrice: $this->options->unitPrice($product, $options),
                discount: (string) max(0, (float) ($item['discount'] ?? 0)),
                note: filled($item['note'] ?? null) ? (string) $item['note'] : null,
                serial: filled($item['serial'] ?? null) ? (string) $item['serial'] : null,
                employeeId: in_array($employeeId, $validEmployees, true) ? $employeeId : null,
                options: $options,
            );
        }

        return $lines;
    }

    /**
     * Decodifica el carrito serializado del formulario. Un JSON inválido se trata como vacío: el
     * llamador ya avisa de que el ticket está vacío, que es el mensaje útil para el cajero.
     *
     * @return array<int, array<string, mixed>>
     */
    public function decode(?string $json): array
    {
        $cart = json_decode((string) $json, true);

        return is_array($cart) ? $cart : [];
    }
}
