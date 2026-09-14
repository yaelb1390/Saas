<?php

declare(strict_types=1);

namespace App\Modules\Printing\Support;

use App\Modules\Core\Support\ModuleRegistry;

/**
 * Qué módulos de BMIA imprimen algo, para la pantalla «Impresora por módulo».
 *
 * Es un SUBCONJUNTO de `ModuleRegistry`, no el catálogo entero: CRM, WhatsApp o IA no emiten un
 * documento que salga por una impresora, así que no tiene sentido pedirles una. Las claves son las
 * mismas de `ModuleRegistry` para que activar/desactivar un módulo en el plan de la empresa siga
 * significando lo mismo aquí.
 */
final class PrintableModules
{
    /**
     * @var array<int, string>
     */
    private const MODULES = ['sales', 'billing', 'loans', 'reports', 'inventory', 'quotes', 'delivery'];

    /**
     * @return array<string, string> clave => etiqueta.
     */
    public static function options(): array
    {
        return array_combine(self::MODULES, array_map(ModuleRegistry::label(...), self::MODULES));
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return self::MODULES;
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::MODULES, true);
    }
}
