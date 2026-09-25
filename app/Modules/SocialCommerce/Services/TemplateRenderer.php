<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Inventory\Models\Product;

/**
 * Rellena `{producto}`, `{precio}`, etc. en el texto de una plantilla.
 *
 * Solo las variables de la lista fija (sección 4 del prompt del proyecto): una variable
 * desconocida se deja TAL CUAL en el texto en vez de borrarse o adivinarse, y
 * {@see self::variablesDesconocidas()} es lo que usa el formulario para rechazarla antes de
 * guardar.
 *
 * `{url_producto}` sale vacía a propósito: este sistema no tiene una página pública de producto
 * todavía. No se inventa una URL que no existe.
 */
final class TemplateRenderer
{
    /** @var list<string> */
    public const VARIABLES = [
        'producto', 'precio', 'moneda', 'sku', 'categoria', 'url_producto', 'url_whatsapp', 'nombre_cliente',
    ];

    public function __construct(private readonly WhatsAppLinkBuilder $whatsapp) {}

    public function render(string $body, Product $product, Company $company, ?string $customerName = null): string
    {
        $valores = $this->valores($product, $company, $customerName);

        return preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn (array $m): string => array_key_exists($m[1], $valores) ? $valores[$m[1]] : $m[0],
            $body,
        ) ?? $body;
    }

    /**
     * Las variables que el texto usa pero que no están en la lista. Vacío si todas son válidas.
     *
     * @return list<string>
     */
    public function variablesDesconocidas(string $body): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $body, $coincidencias);

        return array_values(array_unique(array_diff($coincidencias[1], self::VARIABLES)));
    }

    /**
     * @return array<string, string>
     */
    private function valores(Product $product, Company $company, ?string $customerName): array
    {
        return [
            'producto' => $product->name,
            'precio' => $company->currency.' '.number_format((float) $product->price, 2),
            'moneda' => (string) $company->currency,
            'sku' => (string) $product->sku,
            'categoria' => $product->category?->name ?? '',
            'url_producto' => '',
            'url_whatsapp' => $this->whatsapp->build($company, $product) ?? '',
            'nombre_cliente' => $customerName ?? '',
        ];
    }
}
