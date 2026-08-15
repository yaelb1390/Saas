<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos: el dinero que sale del negocio.
 *
 * Hasta ahora no había forma de anotar uno. Los movimientos financieros solo entraban solos —ventas
 * y préstamos—, así que el saldo de las cuentas era ficticio: subía con cada venta y no bajaba nunca
 * salvo al prestar. Pagar la luz o al proveedor no tenía dónde escribirse.
 *
 * NO hay columna de «método de pago» a propósito: la CUENTA ya lo dice. Si sale de «Caja General» es
 * efectivo; si sale de «Banco Popular» es transferencia o cheque. Guardar las dos cosas invita a que
 * se contradigan, y lo único que el método añadiría de verdad es el número de cheque o de
 * transferencia, que es lo que guarda `reference`.
 *
 * Un gasto aquí es dinero YA PAGADO. Lo que se debe y todavía no se ha pagado es otra cosa —cuentas
 * por pagar— y tendrá su propio sitio; mezclarlas haría que el saldo de la cuenta bajara por facturas
 * que nadie ha pagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code');

            // De dónde salió el dinero. `restrictOnDelete`: borrar una cuenta con gastos dejaría
            // salidas de dinero sin origen y el arqueo sin cuadrar.
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();

            // A quién se le pagó. Opcional: la luz y el alquiler no siempre son un proveedor dado
            // de alta, y exigirlo obligaría a crear fichas de proveedor para pagar la basura.
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_name')->nullable();     // snapshot o nombre suelto

            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->string('reference')->nullable();         // nº de cheque, de transferencia, de factura
            $table->date('paid_at');
            $table->text('notes')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'paid_at']);
            $table->index(['company_id', 'expense_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
