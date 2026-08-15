<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conceptos de gasto: alquiler, luz, nómina, transporte...
 *
 * Es lo que convierte una lista de salidas de dinero en una respuesta a «¿en qué se me va?». Sin
 * categoría, un gasto solo se puede leer de uno en uno, y con doscientos al mes eso no lo lee nadie.
 *
 * Se crean con la empresa (FinanceProvisioner) para que el primer gasto ya tenga dónde clasificarse:
 * si hubiera que inventar la categoría antes de poder anotar la factura de la luz, la gente
 * escribiría el gasto en «Otros» para siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
