<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Borra los DATOS de negocio de una empresa (productos, ventas, préstamos, clientes, finanzas, etc.)
 * conservando el "shell" de la cuenta: empresa, usuarios, sucursales, almacenes, cajas, suscripción,
 * pipelines de CRM y los roles/permisos. Se usa para las pruebas self-service que no se convierten en
 * plan: pasados 24 h del vencimiento se limpian sus datos, pero el cliente mantiene su acceso para
 * contratar y empezar de cero.
 *
 * Se borra tabla por tabla con DB::table()->where('company_id', ...)->delete() (evita el global scope
 * de empresa). El ORDEN va de hijos a padres para respetar las FK `restrict` internas (p. ej.
 * sale_items→products, loans→customers, purchase_orders→suppliers).
 *
 * IMPORTANTE: si un módulo nuevo agrega una tabla con `company_id`, hay que añadirla a $tables en la
 * posición correcta o sus filas quedarán huérfanas tras la purga.
 */
final class TenantDataPurger
{
    /**
     * Tablas de datos de negocio, en orden de borrado (hijos antes que padres). NO incluye el shell:
     * companies, users, branches, warehouses, cash_registers, subscriptions, pipelines,
     * pipeline_stages ni las tablas de roles/permisos.
     *
     * @var list<string>
     */
    private const TABLES = [
        // IA
        'ai_sentiment_analyses',
        'ai_document_chunks',
        'ai_documents',
        // WhatsApp
        'wa_messages',
        'wa_conversations',
        'wa_templates',
        // RRHH
        'attendances',
        'employees',
        // Finanzas
        'financial_movements',
        'accounts',
        // Entregas
        'deliveries',
        // Préstamos (installments/payments también cuelgan de loans, pero se listan por company_id)
        'loan_payments',
        'loan_installments',
        'loans',
        // Facturación
        'invoice_items',
        'invoices',
        'fiscal_sequences',
        // Ventas
        'sale_items',
        'sales',
        // Compras
        'purchase_order_items',
        'purchase_orders',
        'suppliers',
        // Caja (movimientos/sesiones; las cajas en sí se conservan)
        'cash_movements',
        'cash_sessions',
        // CRM (los pipelines/stages se conservan)
        'customer_documents',
        'opportunities',
        'customers',
        // Inventario
        'stock_movements',
        'stock',
        'products',
        'categories',
    ];

    public function purge(Company $company): void
    {
        $companyId = (int) $company->id;

        DB::transaction(function () use ($companyId): void {
            foreach (self::TABLES as $table) {
                DB::table($table)->where('company_id', $companyId)->delete();
            }
        });
    }
}
