<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El alquiler: reserva→activo→devuelto→cerrado, TODO en una sola fila.
 *
 * Igual que `vehicle_deals` ya hace con apartado→venta: una reserva que se confirma y se entrega es
 * el MISMO alquiler avanzando de estado, no una tabla que «se convierte» en otra. Las tarifas y el
 * depósito se congelan aquí al reservar (igual que una cotización): si el vehículo cambia de precio
 * después, un alquiler ya pactado no debe moverse solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicles') || ! Schema::hasTable('customers')) {
            return;
        }

        if (Schema::hasTable('vehicle_rentals')) {
            return;
        }

        Schema::create('vehicle_rentals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // `restrict`: no se borra de debajo un vehículo con alquileres, ni un cliente con historial.
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('code');

            $table->dateTime('start_at');
            $table->dateTime('end_at');
            // Cuándo se entregó/devolvió DE VERDAD, que casi nunca es exactamente lo pactado.
            $table->dateTime('actual_pickup_at')->nullable();
            $table->dateTime('actual_return_at')->nullable();

            // Tarifa y depósito CONGELADOS al reservar, igual que una cotización: si el vehículo
            // cambia de precio después, un alquiler ya pactado no se mueve solo.
            $table->decimal('daily_rate', 15, 2);
            $table->unsignedInteger('days');
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('deposit_amount', 15, 2)->default(0);

            // Se llenan al devolver: lo que se cobra de más por kilómetros, combustible o daños.
            $table->decimal('extra_km_charge', 15, 2)->nullable();
            $table->decimal('fuel_charge', 15, 2)->nullable();
            $table->decimal('damage_charge', 15, 2)->nullable();

            $table->decimal('subtotal', 15, 2);
            $table->decimal('total', 15, 2);
            // Lo que falta por cobrar. Se descuenta en cada abono, igual que en `vehicle_deals.balance`.
            $table->decimal('balance', 15, 2);

            $table->string('status')->default('pending'); // pending, confirmed, active, returned, completed, cancelled
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id']);
            // El índice que sostiene la comprobación de solapamiento: «este vehículo, en este rango».
            $table->index(['company_id', 'vehicle_id', 'start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_rentals');
    }
};
