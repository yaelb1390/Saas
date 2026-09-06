<?php

declare(strict_types=1);

namespace App\Modules\POS\Support;

use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\OptionResolver;
use App\Modules\POS\Exceptions\ProductUnavailableException;
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

        /*
         * EL DESCUENTO APAGADO NO ENTRA, aunque venga en el carrito.
         *
         * El interruptor solo escondía el campo en la pantalla, así que apagarlo no impedía nada:
         * una pestaña abierta de antes del cambio, o un carrito editado a mano, metían la rebaja
         * igual. Es la misma regla que ya rige el precio unas líneas más abajo.
         *
         * SOLO EL DESCUENTO. Los demás interruptores —nº de serie, nota, empleado, cantidad
         * decimal— son preferencias de PRESENTACIÓN: apagados quieren decir «no lo preguntes en
         * esta pantalla», no «este negocio no puede». Filtrarlos aquí borraría datos legítimos que
         * llegan por otras vías. El descuento es distinto porque es dinero que SALE.
         */
        $ajustes = AjustesDelTerminal::activos();

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

            // El guarda de verdad está AQUÍ y no en la rejilla. Un producto agotado se sigue viendo
            // en pantalla —en gris, para poder reactivarlo— así que se puede tocar por descuido, y
            // ocultar algo nunca ha sido protegerlo.
            if (! $product->sePuedeVender()) {
                throw ProductUnavailableException::para((string) $product->name);
            }

            // Tamaños, sabores y extras: se verifican contra los grupos del propio producto y su
            // recargo se lee de la base, nunca del navegador.
            $options = $this->options->resolve($product, (array) ($item['options'] ?? []));

            // Cantidad y descuento se sanean: cantidad > 0 y descuento nunca negativo.
            $employeeId = (int) ($item['employee_id'] ?? 0);
            $employeeId = in_array($employeeId, $validEmployees, true) ? $employeeId : null;

            $lines[] = new SaleLineData(
                productId: $product->id,
                quantity: (string) max(0.001, (float) ($item['qty'] ?? 1)),
                unitPrice: $this->options->unitPrice($product, $options),
                // El único que se comprueba: una rebaja es dinero que sale. Ver el comentario de
                // arriba para por qué los demás campos no se filtran aquí.
                discount: $ajustes->importe('line_discount', $item['discount'] ?? 0),
                note: filled($item['note'] ?? null) ? (string) $item['note'] : null,
                serial: filled($item['serial'] ?? null) ? (string) $item['serial'] : null,
                employeeId: $employeeId,
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
