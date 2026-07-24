<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo del préstamo justo DESPUÉS de este abono. Se guarda para que un recibo reimpreso muestre el
 * total adeudado de esa fecha, no el saldo actual (que baja con cobros posteriores).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_payments', function (Blueprint $table): void {
            $table->decimal('balance_after', 15, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('loan_payments', function (Blueprint $table): void {
            $table->dropColumn('balance_after');
        });
    }
};
