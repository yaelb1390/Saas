<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos aparcados: el cajero deja uno a medias para atender a otro cliente y lo recupera después.
 *
 * No se usa una `Sale` en estado borrador porque consumiría un número de venta («V-000001») antes de
 * cobrar: los listados, los reportes y la numeración fiscal se llenarían de huecos por pedidos que
 * quizá nunca se cobren.
 *
 * El carrito va serializado en `payload`. Guarda QUÉ se eligió (producto, cantidad, opciones), no
 * cuánto cuesta: los precios se releen del catálogo al recuperarlo y al cobrar, así un pedido
 * aparcado ayer no cobra la tarifa de ayer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Turno en el que se aparcó, para rastrear quién lo dejó. Nullable y nullOnDelete: al
            // cerrar la caja el pedido sigue ahí, porque el cliente puede volver mañana.
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Referencia corta y legible («E-03»): el cajero la canta en voz alta.
            $table->string('reference', 20);
            $table->string('customer_name')->nullable();
            $table->json('payload');
            // Solo informativo, para la lista de aparcados. El importe real se recalcula al cobrar.
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->unique(['company_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_orders');
    }
};
