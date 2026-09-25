<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Inventory\Models\Product;
use App\Modules\SocialCommerce\Models\Settings;

/**
 * El enlace `wa.me/...` con el texto ya escrito, para la variable `{url_whatsapp}` y el botón de
 * las plantillas.
 */
final class WhatsAppLinkBuilder
{
    public function build(Company $company, Product $product): ?string
    {
        $ajustes = Settings::withoutGlobalScopes()->where('company_id', $company->id)->first();
        $numero = $ajustes?->whatsapp_number;

        if (blank($numero)) {
            return null;
        }

        $mensaje = "Hola, estoy interesado en {$product->name}.";

        return 'https://wa.me/'.ltrim((string) $numero, '+').'?text='.rawurlencode($mensaje);
    }
}
