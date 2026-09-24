<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para las dos preguntas que la Fase 3 hace de verdad sobre WhatsApp: «¿cuántos mensajes
 * fallaron HOY?» (no en toda la historia, que era lo que medía `PlatformHealthService` hasta ahora) y
 * «¿esta empresa tiene mensajes atascados?». Sin ellos, las dos son un recorrido completo de la tabla
 * más grande del módulo en cada carga del panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wa_messages')) {
            return;
        }

        Schema::table('wa_messages', function (Blueprint $table): void {
            if (! Schema::hasIndex('wa_messages', ['status', 'created_at'])) {
                $table->index(['status', 'created_at']);
            }

            if (! Schema::hasIndex('wa_messages', ['company_id', 'status', 'created_at'])) {
                $table->index(['company_id', 'status', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wa_messages')) {
            return;
        }

        Schema::table('wa_messages', function (Blueprint $table): void {
            foreach ([['status', 'created_at'], ['company_id', 'status', 'created_at']] as $columnas) {
                if (Schema::hasIndex('wa_messages', $columnas)) {
                    $table->dropIndex($columnas);
                }
            }
        });
    }
};
