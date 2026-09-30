<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Días de crédito que da el proveedor (ej. "cobra a 30 días"). Nulo = usa el default de la empresa.
 * Lo usa Cuentas por Pagar para calcular sola la fecha de vencimiento de una orden recibida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn('payment_terms_days');
        });
    }
};
