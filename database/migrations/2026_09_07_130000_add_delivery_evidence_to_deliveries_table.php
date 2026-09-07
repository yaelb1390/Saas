<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La prueba de que el pedido se entregó.
 *
 * POR QUÉ HACE FALTA. Cuando un cliente llama diciendo «a mí no me llegó nada», hoy la única
 * respuesta del sistema es la palabra del repartidor contra la del cliente. Una foto del paquete en
 * la puerta cierra esa discusión en diez segundos y sin que nadie quede como mentiroso.
 *
 * NO TIENE NADA QUE VER CON EL PAGO. El repartidor no cobra; esto solo confirma que la mercancía
 * llegó. Que quede escrito aquí porque es justo la clase de campo al que alguien, dentro de un año,
 * intentará colgarle un importe.
 *
 * SE GUARDA LA RUTA, NO LA IMAGEN. El fichero vive en el disco configurado —en producción R2/S3— y
 * se sirve SIEMPRE con URL firmada, nunca desde un disco público: es la puerta de la casa de un
 * cliente, y una URL adivinable la deja a la vista de cualquiera.
 *
 * El PIN de entrega se deja fuera a propósito: antes de construirlo hay que decidir por dónde le
 * llega al cliente, y esa pregunta no tiene respuesta todavía. La foto no depende de ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deliveries') || Schema::hasColumn('deliveries', 'evidence_path')) {
            return;
        }

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->string('evidence_path')->nullable();
            // Cuándo se tomó. Sirve para saber si la foto es de esta entrega o quedó de un intento
            // anterior: una entrega que falla y se reintenta al día siguiente conserva la fila.
            $table->timestamp('evidence_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('deliveries') || ! Schema::hasColumn('deliveries', 'evidence_path')) {
            return;
        }

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn(['evidence_path', 'evidence_at']);
        });
    }
};
