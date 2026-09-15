<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un abono o cargo sobre un alquiler: depósito, la propia tarifa, kilómetros de más, un daño, el
 * combustible que faltaba. Mismo patrón que `vehicle_deal_payments`, con un `kind` de más para saber
 * QUÉ se cobró y no solo cuánto —hace falta para el desglose del recibo y del reporte de ingresos—.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_rentals') || Schema::hasTable('vehicle_rental_payments')) {
            return;
        }

        Schema::create('vehicle_rental_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_rental_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('method')->default('cash'); // cash, transfer, card, check
            $table->string('kind')->default('rental'); // deposit, rental, extra_km, damage, fuel, other
            $table->string('reference')->nullable();
            $table->dateTime('paid_at');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'vehicle_rental_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_rental_payments');
    }
};
