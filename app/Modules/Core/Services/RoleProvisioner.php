<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Company;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Aprovisiona los permisos globales y los roles por empresa (spatie teams).
 *
 * Los permisos son globales (definiciones compartidas). Los roles pertenecen a una empresa
 * concreta vía company_id (team), de modo que cada tenant administra sus propios roles sin
 * afectar a los demás.
 */
final class RoleProvisioner
{
    /**
     * Catálogo base de permisos de la Fundación. Los módulos siguientes añadirán los suyos.
     *
     * @var array<int, string>
     */
    public const PERMISSIONS = [
        // Core
        'company.manage',
        'branches.manage',
        'warehouses.manage',
        'users.manage',
        'roles.manage',
        'audits.view',
        'dashboard.view',
        // Inventario
        'products.view',
        'products.manage',
        'categories.manage',
        'stock.view',
        'stock.adjust',
        // Compras
        'suppliers.manage',
        'purchases.view',
        'purchases.manage',
        'purchases.receive',
        // Caja
        'cash.view',
        'cash.open',
        'cash.close',
        'cash.manage',
        // Ventas / POS
        'sales.view',
        'sales.create',
        'pos.operate',
        // Facturación
        'invoices.view',
        'invoices.issue',
        'invoices.cancel',          // inutiliza un NCF: no es una acción de caja
        'fiscal_sequences.manage',
        // CRM
        'customers.view',
        'customers.manage',
        'opportunities.view',
        'opportunities.manage',
        // WhatsApp
        'whatsapp.view',
        'whatsapp.send',
        'whatsapp.connect',         // vincula/desvincula la línea de la empresa
        'whatsapp.templates.manage',
        // IA
        'ai.documents.manage',
        'ai.assistant.use',
        'ai.sentiment.view',
        // Delivery
        'delivery.view',
        'delivery.manage',
        // Finanzas
        'finance.view',
        'finance.manage',
        // RRHH
        'hr.view',
        'hr.manage',
        // Reportes
        'reports.view',
    ];

    /**
     * Roles por empresa y los permisos que agrupan.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLES = [
        'owner' => self::PERMISSIONS,
        'admin' => [
            'branches.manage',
            'warehouses.manage',
            'users.manage',
            'audits.view',
            'dashboard.view',
            'products.view',
            'products.manage',
            'categories.manage',
            'stock.view',
            'stock.adjust',
            'suppliers.manage',
            'purchases.view',
            'purchases.manage',
            'purchases.receive',
            'cash.view',
            'cash.open',
            'cash.close',
            'cash.manage',
            'sales.view',
            'sales.create',
            'pos.operate',
            'invoices.view',
            'invoices.issue',
            'invoices.cancel',
            'fiscal_sequences.manage',
            'customers.view',
            'customers.manage',
            'opportunities.view',
            'opportunities.manage',
            'whatsapp.view',
            'whatsapp.send',
            'whatsapp.connect',
            'whatsapp.templates.manage',
            'ai.documents.manage',
            'ai.assistant.use',
            'ai.sentiment.view',
            'delivery.view',
            'delivery.manage',
            'finance.view',
            'finance.manage',
            'hr.view',
            'hr.manage',
            'reports.view',
        ],
        'staff' => [
            'dashboard.view',
            'products.view',
            'stock.view',
            'purchases.view',
            'cash.view',
            'cash.open',
            'cash.close',
            'sales.view',
            'sales.create',
            'pos.operate',
            'invoices.view',
            'invoices.issue',
            'customers.view',
            'customers.manage',
            'opportunities.view',
            'whatsapp.view',
            'whatsapp.send',
            'ai.assistant.use',
            'delivery.view',
            'reports.view',
        ],
    ];

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * Garantiza que existan los permisos globales (idempotente).
     */
    public function ensurePermissions(): void
    {
        $this->registrar->setPermissionsTeamId(null);

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    /**
     * Crea (o actualiza) los roles de una empresa y les asigna sus permisos.
     */
    public function provisionFor(Company $company): void
    {
        $this->ensurePermissions();
        $this->registrar->setPermissionsTeamId($company->id);

        foreach (self::ROLES as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
        }
    }
}
