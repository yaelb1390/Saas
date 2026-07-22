<?php

declare(strict_types=1);

namespace App\Modules\POS\Support;

use App\Modules\Core\Models\Company;

/**
 * Perfiles de negocio del Punto de Venta. Un perfil es un preajuste de las opciones del POS pensado
 * para un tipo de comercio; el operador puede afinar cada interruptor después. Vive en POS porque es
 * quien consume la configuración; la persistencia es un JSON en `companies.settings['pos']`.
 *
 * Fuente única de: qué tipos existen, qué opciones hay y qué trae encendido cada tipo. Así la vista
 * de ajustes, el modelo Company y el POS leen todos la misma verdad.
 */
final class PosProfile
{
    public const DEFAULT = 'general';

    /**
     * Opciones configurables (todas booleanas). El orden es el de presentación.
     *
     * @var array<string, string>
     */
    private const OPTIONS = [
        'line_discount' => 'Descuento por línea',
        'global_discount' => 'Descuento del ticket',
        'tip' => 'Propina',
        'attendant' => 'Empleado que atiende',
        'serial' => 'Nº de serie / IMEI',
        'line_note' => 'Nota por línea',
        'decimal_qty' => 'Cantidad decimal (peso/medida)',
        'services' => 'Servicios (sin stock)',
    ];

    /**
     * Tipos de negocio y su etiqueta + descripción.
     *
     * @var array<string, array{label: string, hint: string}>
     */
    private const TYPES = [
        'general' => ['label' => 'General', 'hint' => 'Comercio estándar'],
        'ropa' => ['label' => 'Ropa y calzado', 'hint' => 'Tienda de ropa'],
        'repuestos' => ['label' => 'Repuestos', 'hint' => 'Piezas de vehículo'],
        'salon' => ['label' => 'Salón / Uñas', 'hint' => 'Belleza y servicios'],
        'tecnologia' => ['label' => 'Tecnología', 'hint' => 'Equipos y electrónica'],
    ];

    /**
     * Opciones encendidas por defecto en cada tipo (las no listadas quedan en false).
     *
     * @var array<string, array<int, string>>
     */
    private const PRESETS = [
        'general' => ['line_discount', 'global_discount'],
        'ropa' => ['line_discount', 'global_discount'],
        'repuestos' => ['line_discount', 'line_note'],
        'salon' => ['tip', 'attendant', 'services', 'line_note'],
        'tecnologia' => ['serial', 'line_note', 'line_discount'],
    ];

    /**
     * @return array<string, array{label: string, hint: string}>
     */
    public static function types(): array
    {
        return self::TYPES;
    }

    /**
     * @return array<string, string>
     */
    public static function optionLabels(): array
    {
        return self::OPTIONS;
    }

    /**
     * @return array<int, string>
     */
    public static function optionKeys(): array
    {
        return array_keys(self::OPTIONS);
    }

    public static function isType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? self::TYPES[self::DEFAULT]['label'];
    }

    /**
     * Opciones por defecto de un tipo: todas las claves con su booleano según el preset.
     *
     * @return array<string, bool>
     */
    public static function defaults(string $type): array
    {
        $on = self::PRESETS[$type] ?? self::PRESETS[self::DEFAULT];

        return array_map(
            static fn (string $key): bool => in_array($key, $on, true),
            array_combine(self::optionKeys(), self::optionKeys()),
        );
    }

    /**
     * Configuración efectiva del POS de una empresa: el tipo elegido y las opciones resultantes de
     * mezclar los ajustes manuales sobre los valores por defecto del tipo. Cualquier clave ausente o
     * inválida cae a su valor por defecto, de modo que el POS nunca recibe una opción indefinida.
     *
     * @return array{profile: string, options: array<string, bool>}
     */
    public static function for(Company $company): array
    {
        $settings = $company->settings['pos'] ?? [];

        $type = is_string($settings['profile'] ?? null) && self::isType($settings['profile'])
            ? $settings['profile']
            : self::DEFAULT;

        $overrides = is_array($settings['options'] ?? null) ? $settings['options'] : [];
        $options = self::defaults($type);

        foreach ($options as $key => $default) {
            if (array_key_exists($key, $overrides)) {
                $options[$key] = (bool) $overrides[$key];
            }
        }

        return ['profile' => $type, 'options' => $options];
    }
}
