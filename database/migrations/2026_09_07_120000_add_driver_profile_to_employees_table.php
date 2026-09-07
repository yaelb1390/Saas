<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hace falta saber de un repartidor y la ficha de empleado no guardaba.
 *
 * Hasta ahora un empleado tenía nombre, correo, puesto, salario y fecha de alta. Sirve para la
 * nómina; no sirve para el reparto. Quien asigna una entrega necesita saber en QUÉ se mueve —no es
 * lo mismo mandar un sofá en motor que en camioneta— y cómo llamarlo si no contesta el cliente.
 *
 * `rating` es opcional a propósito: null quiere decir «todavía no se ha calificado», que no es lo
 * mismo que cero. Un repartidor nuevo con un cero de salida parecería el peor de la plantilla.
 *
 * NO SE TOCA `salary`. Sigue donde estaba, y el portal del repartidor no lo enseña: es información
 * financiera, y esa pantalla no muestra ninguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees') || Schema::hasColumn('employees', 'vehicle')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            // En qué se mueve: «Motor Honda 125», «Camioneta blanca». Texto libre y no una lista
            // cerrada: cada negocio reparte con lo que tiene, y una lista se queda corta el primer día.
            $table->string('vehicle')->nullable();
            $table->string('phone', 40)->nullable();
            // De 0 a 5 con un decimal. Null = sin calificar todavía.
            $table->decimal('rating', 2, 1)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employees') || ! Schema::hasColumn('employees', 'vehicle')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['vehicle', 'phone', 'rating']);
        });
    }
};
