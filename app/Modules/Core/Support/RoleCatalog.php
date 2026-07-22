<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Presentación de los roles de empresa: etiqueta legible y una línea de ayuda.
 *
 * Los nombres técnicos (owner/admin/staff) viven en RoleProvisioner, que es la fuente de verdad
 * de qué permisos agrupa cada rol. Aquí solo se traduce para la interfaz.
 */
final class RoleCatalog
{
    /**
     * @var array<string, array{label: string, hint: string}>
     */
    private const ROLES = [
        'owner' => ['label' => 'Propietario', 'hint' => 'Control total del negocio.'],
        'admin' => ['label' => 'Administrador', 'hint' => 'Gestiona todo salvo la propia empresa.'],
        'staff' => ['label' => 'Cajero / Personal', 'hint' => 'Opera el POS y factura; sin acceso a RRHH ni finanzas.'],
    ];

    /**
     * Roles que se pueden asignar desde el panel (el orden es el de jerarquía).
     *
     * @return array<int, string>
     */
    public static function assignable(): array
    {
        return array_keys(self::ROLES);
    }

    public static function label(string $role): string
    {
        return self::ROLES[$role]['label'] ?? ucfirst($role);
    }

    public static function hint(string $role): string
    {
        return self::ROLES[$role]['hint'] ?? '';
    }

    public static function isAssignable(string $role): bool
    {
        return array_key_exists($role, self::ROLES);
    }
}
