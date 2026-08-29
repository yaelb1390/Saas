<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se le hace a una unidad antes de venderla: pintura, gomas, transmisión, papeles.
 *
 * Es lo que convierte el costo de COMPRA en el costo REAL. Un dealer que compra en 400 mil y gasta
 * 90 mil en dejarlo presentable no ganó 100 mil vendiendo en 500: ganó 10. Sin esta tabla el margen
 * de la pantalla sería una cifra bonita y falsa, que es peor que no tener cifra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Si se borra la unidad se van sus trabajos: no significan nada sin el carro al que se
            // le hicieron.
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('cost', 15, 2)->default(0);
            // Quién lo hizo, en texto: casi siempre es un taller de fuera que no es proveedor
            // registrado del sistema. Obligar a darlo de alta para anotar un cambio de gomas haría
            // que nadie lo anotara.
            $table->string('performed_by')->nullable();
            $table->string('status')->default('pending'); // pending, done
            $table->date('performed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'vehicle_id']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_jobs');
    }
};
