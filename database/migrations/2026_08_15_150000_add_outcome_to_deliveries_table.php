<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por qué se cerró una entrega.
 *
 * Hasta ahora la única salida cuando algo iba mal era «No se pudo entregar», sin decir nada más. Con
 * eso el negocio nunca aprende: tres pedidos perdidos por una dirección mal tomada y tres porque el
 * cliente cambió de idea se ven exactamente igual, y solo el primero tiene arreglo.
 *
 * `outcome_note` es la nota del repartidor y va APARTE de `notes`: ahí están las señas de la casa
 * («portón azul, tocar el timbre de abajo») y machacarlas dejaría al siguiente reparto a ciegas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->string('outcome_reason', 40)->nullable()->after('status');
            $table->text('outcome_note')->nullable()->after('outcome_reason');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn(['outcome_reason', 'outcome_note']);
        });
    }
};
