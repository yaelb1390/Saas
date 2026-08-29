<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El inventario de un dealer de vehículos.
 *
 * NO son productos, y esa es la decisión que manda sobre todo el módulo. `products` + `product_stock`
 * modelan CANTIDAD por almacén y UN precio por SKU. Un vehículo es una unidad serializada: un chasis,
 * un costo de compra propio, unos gastos de preparación propios y un precio propio.
 *
 * Meterlo como producto con existencia 1 rompe por tres sitios a la vez: dos Corolla 2019 serían el
 * mismo SKU con costos distintos —y el margen saldría mal en los dos—, el punto de venta dejaría
 * vender 3 unidades de un carro del que solo hay uno, y no habría dónde colgar los gastos de
 * preparación de ESA unidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Dónde está parado. Nulo si el dealer no separa por sucursal.
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code');

            /*
             * El chasis. Único POR EMPRESA y no globalmente: dos dealers distintos pueden llegar a
             * tener el mismo carro en momentos distintos —uno se lo compra al otro—, y un único
             * global impediría registrarlo al segundo.
             *
             * Nulo permitido: un carro puede entrar al patio antes de que alguien copie el chasis.
             */
            $table->string('vin')->nullable();

            $table->string('make');                 // marca
            $table->string('model');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('trim')->nullable();     // versión: LX, EX, Limited...
            $table->string('color')->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->string('fuel')->nullable();
            $table->string('transmission')->nullable();
            $table->string('plate')->nullable();

            // Lo que costó comprarlo. Los gastos de preparación NO se guardan aquí: viven en
            // `vehicle_jobs` y se suman. Guardar un total acumulado sería una segunda verdad que
            // hay que acordarse de actualizar cada vez, y el día que alguien no lo haga el margen
            // miente sin avisar.
            $table->decimal('purchase_cost', 15, 2)->default(0);
            $table->decimal('asking_price', 15, 2)->default(0);

            $table->string('status')->default('available'); // available, reserved, sold, withdrawn
            $table->date('acquired_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'vin']);
            // La rejilla filtra por marca y modelo constantemente: es lo primero que teclea quien
            // atiende cuando el cliente pregunta «¿tienes una Honda?».
            $table->index(['company_id', 'make', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
