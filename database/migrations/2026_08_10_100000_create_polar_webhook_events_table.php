<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de los avisos recibidos de Polar.
 *
 * Cumple dos funciones, y la primera es la importante:
 *
 * 1. IDEMPOTENCIA. Polar reintenta cada aviso hasta 10 veces si no recibe un 2xx a tiempo, así que
 *    el MISMO evento llegará repetido tarde o temprano. El índice único sobre `event_id` es lo que
 *    impide que un solo pago extienda el período dos veces. No es un detalle defensivo: sin él, un
 *    reintento por un simple tiempo de espera regalaría un mes de servicio.
 *
 * 2. Rastro para auditar. Cuando un pago no acabe activando nada (empresa no identificada, producto
 *    sin plan enlazado), aquí queda el cuerpo entero para resolverlo a mano y saber qué pasó.
 *
 * Vive en la plataforma, no en una empresa: por eso `company_id` es opcional (al recibir el aviso
 * todavía puede no saberse a quién corresponde) y la tabla no lleva ámbito de tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polar_webhook_events', function (Blueprint $table): void {
            $table->id();

            // Cabecera `webhook-id`. La llave de la idempotencia: único a propósito.
            $table->string('event_id')->unique();

            $table->string('type');

            // applied | ignored | unresolved — qué se hizo con el aviso.
            $table->string('result')->default('received');

            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('note')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polar_webhook_events');
    }
};
