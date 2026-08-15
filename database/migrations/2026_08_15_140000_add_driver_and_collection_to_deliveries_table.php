<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que le faltaba a una entrega para servir de algo.
 *
 * El módulo existía como una tabla y una pantalla de solo lectura: no había forma de crear una
 * entrega, ni de asignarla, ni de cambiarle el estado. Y aunque la hubiera, a la fila le faltaba lo
 * esencial: de qué venta salió, quién la lleva y cuánto tiene que cobrar.
 *
 * Ese último punto es el que más duele en un colmado: el motorista sale con mercancía y vuelve con
 * efectivo, y hasta que lo entrega ese dinero no está en ningún sitio. Sin anotarlo, el arqueo del
 * día canta un faltante que en realidad va en la mochila de alguien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            /*
             * El repartidor, ahora un EMPLEADO y no un nombre suelto.
             *
             * `driver_name` se conserva —no se borra ninguna columna— porque es el histórico de lo
             * que ya se apuntó a mano, y porque sigue valiendo para el motorista de una vez que no
             * está dado de alta. Al asignar un empleado se guarda también su nombre ahí, así que la
             * entrega dice quién la llevó aunque mañana se borre la ficha.
             */
            $table->foreignId('employee_id')->nullable()->after('driver_name')->constrained()->nullOnDelete();

            // Cuánto tiene que cobrar el motorista al entregar. Cero = ya está pagada.
            $table->decimal('amount_to_collect', 15, 2)->default(0)->after('employee_id');

            // Cobró al cliente. El dinero está con él.
            $table->timestamp('collected_at')->nullable()->after('delivered_at');

            // Entregó ese dinero en caja. Aquí se cierra el círculo.
            $table->timestamp('settled_at')->nullable()->after('collected_at');
            $table->foreignId('settled_by')->nullable()->after('settled_at')->constrained('users')->nullOnDelete();

            // Para «¿cuánto trae encima cada motorista?», que es la consulta del cierre del día.
            $table->index(['company_id', 'employee_id', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'employee_id', 'settled_at']);
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn(['settled_at', 'collected_at', 'amount_to_collect']);
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
