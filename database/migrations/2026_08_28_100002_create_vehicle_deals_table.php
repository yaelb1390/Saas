<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El trato: apartar una unidad o venderla.
 *
 * Apartado y venta son la MISMA fila en dos estados, no dos tablas. En un dealer el apartado se
 * convierte en venta casi siempre, y separarlos obligaría a copiar el cliente, el precio pactado y
 * el inicial de una tabla a otra —que es donde se pierden los datos—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_deals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // `restrict`: no se borra de debajo una unidad que está vendida o apartada.
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            /*
             * El nombre del cliente, copiado al cerrar.
             *
             * Mismo criterio que en préstamos y ventas: si el cliente se renombra o se archiva, el
             * papel del trato tiene que seguir diciendo a quién se le vendió aquel día.
             */
            $table->string('customer_name')->nullable();

            $table->string('code');
            $table->decimal('agreed_price', 15, 2);
            $table->decimal('down_payment', 15, 2)->default(0);

            /*
             * El carro recibido en parte de pago, si lo hubo.
             *
             * Apunta a la MISMA tabla: lo que entra como parte de pago es una unidad más del patio y
             * se vuelve a vender. Tratarlo como otra cosa obligaría a copiarlo a `vehicles` a mano
             * cuando el dealer quisiera ponerlo a la venta.
             */
            $table->foreignId('trade_in_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->decimal('trade_in_value', 15, 2)->default(0);

            // none: de contado. installments: financiado por el propio dealer.
            $table->string('financing')->default('none');
            $table->decimal('interest_rate', 8, 2)->default(0);
            $table->decimal('interest_amount', 15, 2)->default(0);
            $table->string('frequency')->nullable();  // weekly, biweekly, monthly
            $table->unsignedSmallInteger('installments_count')->default(0);
            $table->date('start_date')->nullable();
            // Lo que falta por cobrar: precio − inicial − parte de pago + interés − abonos.
            $table->decimal('balance', 15, 2)->default(0);

            $table->string('status')->default('reserved'); // reserved, closed, cancelled
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'vehicle_id']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_deals');
    }
};
