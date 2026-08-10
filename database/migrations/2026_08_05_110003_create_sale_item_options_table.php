<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las opciones elegidas en una línea de venta, congeladas.
 *
 * Se copian los NOMBRES, no solo el `option_id`: un recibo es un documento del pasado. Si mañana
 * renombran «2 bolas» a «Doble» o suben el recargo, lo vendido ayer debe seguir diciendo lo que
 * decía y costando lo que costó. Por eso `option_id` es nullOnDelete: sirve para trazar el origen
 * mientras exista, pero borrar la opción no puede vaciar el histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_item_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->nullable()->constrained('options')->nullOnDelete();
            $table->string('group_name');
            $table->string('option_name');
            $table->decimal('price_delta', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['company_id', 'sale_item_id'], 'sale_item_options_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_options');
    }
};
