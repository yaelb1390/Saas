<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué usuarios sufrieron cada error, y cuántas veces cada uno.
 *
 * Responde a «¿a cuántas PERSONAS afecta?», que no es lo mismo que cuántas veces ocurrió: una sola persona
 * pulsando diez veces un botón roto son diez ocurrencias y un afectado.
 *
 * Una fila por (error, usuario). `company_id` es la empresa del usuario cuando se conoce; nulo para el
 * operador de la plataforma. Sin claves foráneas hacia usuarios y empresas, como el resto de tablas de
 * registro; sí hacia el error, para que podarlo se lleve también este desglose.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('error_event_users') || ! Schema::hasTable('error_events')) {
            return;
        }

        Schema::create('error_event_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('error_event_id')->constrained('error_events')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->unique(['error_event_id', 'user_id']);

            // «¿Qué errores ha sufrido este usuario?» y el borrado de una empresa, que busca por aquí.
            $table->index(['user_id', 'last_seen_at']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_event_users');
    }
};
