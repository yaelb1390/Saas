<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde vive el cliente, en coordenadas.
 *
 * POR QUÉ HACE FALTA. Hasta ahora la entrega solo guardaba la dirección escrita, y una dirección
 * dominicana de verdad es «Juana #6, callejón blanco». Ningún buscador la resuelve, así que el
 * repartidor acaba llamando por teléfono para que le expliquen cómo llegar — que es justo lo que la
 * pantalla debería ahorrarle.
 *
 * La salida no es adivinar la dirección: es APRENDERLA. La primera vez se navega por el texto; cuando
 * el repartidor llega y pulsa «Guardar esta ubicación», queda el punto exacto para la próxima.
 *
 * DOS TABLAS, Y CADA UNA RESPONDE UNA PREGUNTA DISTINTA:
 *   - `deliveries`  → dónde se entregó DE VERDAD. Es el registro de ese reparto concreto.
 *   - `customers`   → dónde vive el cliente. Es lo que se reutiliza en el siguiente pedido.
 * Con una sola columna habría que elegir entre las dos, y son cosas diferentes: una entrega puede
 * dejarse en el trabajo del cliente sin que eso cambie dónde vive.
 *
 * `decimal(10,7)` Y NO `float`. Siete decimales son ~1 cm, de sobra para una puerta; y en coma
 * flotante dos lecturas idénticas pueden no comparar iguales, con lo que «¿es el mismo punto?» deja
 * de tener respuesta fiable.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['deliveries', 'customers'] as $tabla) {
            if (! Schema::hasTable($tabla) || Schema::hasColumn($tabla, 'latitude')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table): void {
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['deliveries', 'customers'] as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'latitude')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table): void {
                $table->dropColumn(['latitude', 'longitude']);
            });
        }
    }
};
