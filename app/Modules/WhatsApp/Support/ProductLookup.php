<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Support;

use App\Modules\Inventory\Models\Product;

/**
 * Qué productos tiene el negocio que se parezcan a lo que preguntó el cliente.
 *
 * LA REGLA QUE NO SE NEGOCIA: de aquí NUNCA sale `cost`.
 *
 * El precio de coste vive en la misma tabla y en la columna de al lado de `price`. Es el margen del
 * negocio, y filtrarlo por WhatsApp a un cliente —o peor, a la competencia preguntando— sería el
 * peor fallo posible de este módulo. Por eso se seleccionan columnas EXPLÍCITAS y nunca el modelo
 * entero: con `Product::where(...)->get()` bastaría con que el proveedor de IA decidiera resumir «lo
 * que sabe del producto» para que se escapara.
 */
final class ProductLookup
{
    /** Más de esto no cabe en un mensaje de WhatsApp sin que nadie lo lea. */
    private const MAXIMO = 6;

    /**
     * @return list<array{nombre: string, precio: string, hay: bool, descripcion: string|null}>
     */
    public function buscar(string $texto): array
    {
        $palabras = self::palabrasUtiles($texto);

        if ($palabras === []) {
            return [];
        }

        $consulta = Product::query()
            // Columnas explícitas. `cost` no está, y ese es el punto.
            ->select(['name', 'description', 'price', 'is_active', 'is_available'])
            ->where('is_active', true);

        $consulta->where(function ($q) use ($palabras): void {
            foreach ($palabras as $palabra) {
                $q->orWhere('name', 'like', '%'.$palabra.'%');
            }
        });

        return $consulta->limit(self::MAXIMO)->get()
            ->map(static fn (Product $p): array => [
                'nombre' => (string) $p->name,
                'precio' => (string) $p->price,
                // `sePuedeVender()` junta las dos banderas, que NO son lo mismo: `is_active` es «ya
                // no lo vendemos» y `is_available` es «se acabó, vuelve mañana». Al cliente hay que
                // contestarle distinto, y aquí ya solo llegan los activos.
                'hay' => $p->sePuedeVender(),
                'descripcion' => $p->description,
            ])
            ->all();
    }

    /**
     * Las palabras por las que vale la pena buscar.
     *
     * Se cortan las de menos de tres letras y las vacías: sin eso, «me das el precio de la batida»
     * buscaría productos que contengan «de» y devolvería el catálogo entero, que es peor que no
     * encontrar nada.
     *
     * @return list<string>
     */
    private static function palabrasUtiles(string $texto): array
    {
        $vacias = [
            'que', 'cual', 'como', 'para', 'por', 'con', 'los', 'las', 'una', 'uno', 'del',
            'tienen', 'tienes', 'hay', 'precio', 'cuesta', 'vale', 'cuanto', 'quiero', 'dame',
            'me', 'da', 'el', 'la', 'de', 'un', 'es', 'y', 'o', 'a',
        ];

        $texto = mb_strtolower(trim($texto));
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
        $texto = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $texto) ?? $texto;

        return array_values(array_filter(
            preg_split('/\s+/', $texto) ?: [],
            static fn (string $p): bool => mb_strlen($p) >= 3 && ! in_array($p, $vacias, true),
        ));
    }
}
