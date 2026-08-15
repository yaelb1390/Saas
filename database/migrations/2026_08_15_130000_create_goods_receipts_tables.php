<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entradas de mercancía: el documento de lo que llegó al almacén.
 *
 * Antes no había documento. Cada producto se metía de uno en uno y quedaba como un «ajuste» suelto
 * con la nota «Entrada de mercancía»: sin proveedor, sin número de factura y sin costo. Con eso, la
 * pregunta más normal del mundo —«¿qué entró el día 15 y de quién?»— no tenía respuesta, y una remesa
 * de treinta artículos eran treinta recargas de página.
 *
 * Ahora la remesa es UNA cosa con sus líneas, igual que una venta tiene las suyas. Los movimientos de
 * existencia siguen siendo la verdad del almacén; este documento dice de dónde vinieron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code');

            // Un almacén por remesa: la mercancía llega a un sitio. Permitir uno por línea
            // complicaría la pantalla para un caso que casi nadie tiene.
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            // Todo lo del proveedor es OPCIONAL: hay que poder seguir metiendo existencia rápido, sin
            // rellenar nada, que es como se usa a diario. Quien lo rellene gana el histórico.
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('reference')->nullable();          // nº de factura o de conduce

            $table->date('received_at');
            $table->text('notes')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'received_at']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->decimal('quantity', 15, 3);               // misma escala que la existencia
            $table->decimal('unit_cost', 15, 2)->nullable();  // lo que costó ESTA vez

            /*
             * Qué pasó con el costo del producto.
             *
             * `cost_updated` guarda la DECISIÓN de la persona, no el resultado de compararlo: meses
             * después, saber que alguien vio el cambio y decidió no aplicarlo vale tanto como saber
             * que lo aplicó. `previous_cost` deja el rastro de qué había antes.
             */
            $table->boolean('cost_updated')->default(false);
            $table->decimal('previous_cost', 15, 2)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
