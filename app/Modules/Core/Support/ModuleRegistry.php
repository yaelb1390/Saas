<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Catálogo central de los módulos comercializables del sistema.
 *
 * Una empresa contrata un subconjunto de estos módulos (el plan). Dashboard, Reportes y la
 * administración de Usuarios son el núcleo: siempre están disponibles y no se listan aquí.
 *
 * La clave es estable (se guarda en la base de datos); la etiqueta es solo presentación.
 */
final class ModuleRegistry
{
    /**
     * @var array<string, string>
     */
    private const MODULES = [
        'pos' => 'Punto de Venta',
        'inventory' => 'Inventario',
        'sales' => 'Ventas',
        'purchasing' => 'Compras',
        'crm' => 'CRM',
        'whatsapp' => 'WhatsApp',
        'billing' => 'Facturación',
        'finance' => 'Finanzas',
        'delivery' => 'Entregas',
        'hr' => 'Recursos Humanos',
        'ai' => 'IA & RAG',
        'reports' => 'Reportes',
    ];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::MODULES;
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }

    public static function label(string $key): string
    {
        return self::MODULES[$key] ?? ucfirst($key);
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::MODULES);
    }

    /**
     * Filtra una lista de claves dejando solo las válidas (defensa ante datos manipulados).
     *
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    public static function sanitize(array $keys): array
    {
        return array_values(array_filter(
            array_map('strval', $keys),
            static fn (string $key): bool => self::exists($key),
        ));
    }
}
