<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Support;

use App\Modules\Inventory\Models\Product;

/**
 * Lo que le hace falta a `TemplateRenderer`/`WhatsAppLinkBuilder` para armar el mensaje: un nombre
 * y un precio, venga de un producto real o de lo que el dueño puso a mano en la regla.
 *
 * Sin esto, esos dos servicios tendrían que saber si la regla es manual o de producto —una cosa
 * que no les toca decidir— y esa rama acabaría duplicada en cada uno.
 */
final readonly class PricedItem
{
    private function __construct(
        public string $name,
        public float $price,
        public ?string $sku,
        public ?string $categoryName,
    ) {}

    public static function fromProduct(Product $product): self
    {
        return new self(
            name: $product->name,
            price: (float) $product->price,
            sku: $product->sku,
            categoryName: $product->category?->name,
        );
    }

    public static function manual(string $name, float $price): self
    {
        // Sin sku ni categoría: no existen para algo que no está en Inventario. Las plantillas que
        // usen esas variables simplemente salen vacías, no rotas (ver TemplateRenderer::valores()).
        return new self(name: $name, price: $price, sku: null, categoryName: null);
    }
}
