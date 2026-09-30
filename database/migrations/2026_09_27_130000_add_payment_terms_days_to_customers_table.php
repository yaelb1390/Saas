<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Días de crédito del cliente (ej. "paga a 30 días"). Nulo = usa el default de la empresa.
 * Lo usa Cuentas por Cobrar para calcular sola la fecha de vencimiento de una venta a crédito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('payment_terms_days');
        });
    }
};
