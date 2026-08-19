<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El interruptor del asistente, empresa por empresa.
 *
 * Va como columna propia y NO dentro de `settings`, que es donde viven las funciones que el cliente
 * enciende por su cuenta (`Company::FEATURES`). Aquí manda el operador de la plataforma, porque es
 * quien paga cada pregunta al proveedor de IA: si el cliente pudiera encenderlo, el gasto lo
 * decidiría alguien que no lo paga.
 *
 * Apagado por omisión, también para las empresas que ya existen. Encender solo una función que
 * cuesta dinero es una decisión que se toma mirando, no algo que aparezca de repente en la factura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('ai_assistant')->default(false)->after('modules');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('ai_assistant');
        });
    }
};
