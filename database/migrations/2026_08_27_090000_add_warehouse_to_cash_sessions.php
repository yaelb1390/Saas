<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qué almacén sale la mercancía que se cobra en este turno.
 *
 * Hasta ahora el punto de venta descontaba SIEMPRE del almacén marcado por omisión, escrito a fuego.
 * Mientras cada empresa tiene un solo almacén no se nota; el día que alguien crea el segundo, la
 * mercancía que reciba ahí no se puede vender: el cobro la busca en «Principal», no la encuentra y la
 * venta se cae por existencia insuficiente. Y «Entrada de mercancía» sí pregunta a qué almacén entra,
 * así que el sistema deja meter algo donde luego no se puede sacar.
 *
 * Va en la SESIÓN y no en cada venta porque quien atiende un mostrador no cambia de almacén entre un
 * cliente y el siguiente: lo elige al abrir la caja y se olvida. Preguntarlo en cada ticket sería un
 * dato más que confirmar cincuenta veces al día, y un despiste ahí es un descuadre de inventario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table): void {
            /*
             * NULLABLE, y es lo que hace que esto se pueda desplegar.
             *
             * Aquí las migraciones se aplican a mano y puede haber cajas abiertas en ese momento. Una
             * columna obligatoria dejaría a media jornada sin poder cobrar hasta que alguien cerrara
             * el turno. Con null, esas sesiones siguen contra el almacén de por omisión —lo que hacían
             * ayer— y las que se abran a partir de ahora ya traen el suyo.
             */
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('cash_register_id')
                ->constrained('warehouses')
                // Restrict y no cascade: borrar un almacén no puede llevarse por delante el historial
                // de las cajas que vendieron desde él.
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
