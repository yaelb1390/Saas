<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza las ventas, facturas y entregas con el cliente del CRM.
 *
 * Hasta ahora estos documentos solo guardaban «customer_name» (texto libre), lo que impedía saber
 * de forma fiable qué documentos pertenecen a un cliente: sin este enlace no hay portal del
 * cliente, ni historial por cliente, ni CRM con contexto real de compra.
 *
 * Decisiones:
 *
 * - «customer_id» es NULLABLE: la venta de mostrador del POS no identifica a nadie, y ese es el
 *   caso mayoritario. Obligarlo rompería el flujo de venta rápida.
 * - Se conserva «customer_name»: es el nombre histórico impreso en el documento (fiscal, en el
 *   caso de la factura) y no debe cambiar si mañana se corrige la ficha del CRM.
 * - «nullOnDelete»: borrar un cliente no puede llevarse por delante la venta ni, sobre todo, una
 *   factura fiscal ya emitida; el documento sobrevive con su nombre histórico.
 * - Las tres tablas llevan el enlace propio (en vez de derivarlo de la venta) porque tanto las
 *   facturas como las entregas pueden existir sin venta («sale_id» es nullable en ambas).
 *
 * El índice es compuesto con company_id porque toda consulta del sistema filtra por empresa
 * (CompanyScope): un índice solo por customer_id no lo aprovecharía.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = ['sales', 'invoices', 'deliveries'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('customer_id')->nullable()->after('company_id')
                    ->constrained()->nullOnDelete();

                $table->index(['company_id', 'customer_id']);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['company_id', 'customer_id']);
                $table->dropConstrainedForeignId('customer_id');
            });
        }
    }
};
