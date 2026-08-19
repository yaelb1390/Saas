<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los errores dejan de perderse.
 *
 * Hasta ahora no se guardaba ninguno: todo acababa en un `Log::error` que en producción va a la
 * salida de errores de Vercel, con la retención que Vercel quiera y sin forma de buscar. Cuando un
 * cliente decía «no me funcionó», no había absolutamente nada que mirar.
 *
 * AGRUPADO POR HUELLA, no una fila por vez. El mismo fallo repetido mil veces es una fila con un
 * contador en mil, no mil filas: si no, el primer error en bucle llena la tabla y entierra a los
 * demás, que es justo cuando más falta hace leerla.
 *
 * La huella es clase + origen (fichero y línea). El mensaje cambia entre repeticiones —lleva ids,
 * nombres— y meterlo en la huella partiría en mil grupos lo que es un solo fallo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint')->unique();
            $table->string('class');
            $table->text('message');
            $table->string('origin')->nullable();
            $table->text('frames')->nullable();
            $table->text('url')->nullable();
            // Sin foráneas: un error puede ocurrir sin sesión, o después de que la empresa o el
            // usuario dejen de existir. Perder el error por eso sería absurdo.
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // La pantalla ordena por «lo último que falló»: sin este índice sería recorrer la tabla.
            $table->index('last_seen_at');
            $table->index(['company_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
