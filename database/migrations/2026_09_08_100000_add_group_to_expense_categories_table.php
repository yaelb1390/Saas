<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La categoría fija a la que pertenece cada concepto de gasto.
 *
 * Hasta ahora los gastos tenían UN solo nivel: el concepto que cada negocio se inventa —«Café en
 * grano», «Gas del camión»—. Sirve para anotar, pero no para presupuestar ni comparar: dos cafeterías
 * escriben el mismo gasto de tres maneras distintas, y no hay forma de preguntar «¿cuánto se me va en
 * comida?» sin leerlos uno a uno.
 *
 * Ahora cada concepto pertenece a una CATEGORÍA de una lista cerrada (ver `ExpenseGroup`), y de ahí
 * salen el presupuesto, la tabla dinámica y el análisis.
 *
 * VA EN EL CONCEPTO Y NO EN EL GASTO, a propósito. Un concepto pertenece siempre a la misma
 * categoría; guardarla en cada gasto repetiría el dato miles de veces y dejaría la puerta abierta a
 * que dos gastos del mismo concepto acabaran clasificados distinto. Además, así los gastos YA
 * REGISTRADOS quedan clasificados en cuanto se clasifican sus conceptos, sin tocar una sola fila de
 * `expenses`.
 *
 * NULL NO ES «OTROS». Se deja anulable y sin valor por omisión: null significa «nadie lo ha
 * clasificado todavía» y la pantalla lo enseña como tal, para que se note. Rellenarlo con «Otros» de
 * salida haría que doce conceptos viejos pareciesen revisados sin que nadie los hubiera mirado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expense_categories') || Schema::hasColumn('expense_categories', 'category')) {
            return;
        }

        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->string('category', 30)->nullable()->after('name');

            // Se filtra y se agrupa por esto en cada carga de la pantalla de gastos.
            $table->index(['company_id', 'category']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('expense_categories') || ! Schema::hasColumn('expense_categories', 'category')) {
            return;
        }

        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'category']);
            $table->dropColumn('category');
        });
    }
};
