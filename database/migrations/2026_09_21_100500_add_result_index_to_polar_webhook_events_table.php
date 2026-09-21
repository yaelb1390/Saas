<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los avisos de cobro sin resolver se cuentan sin recorrer la tabla.
 *
 * El monitoreo cuenta los avisos de Polar que no se pudieron aplicar (`unresolved`) y los que se quedaron
 * atascados sin procesar (`received` de hace rato). Esa es justo la consulta que filtra por `result`, y
 * hasta hoy no había índice en esa columna: solo `(type, created_at)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('polar_webhook_events') || Schema::hasIndex('polar_webhook_events', ['result', 'created_at'])) {
            return;
        }

        Schema::table('polar_webhook_events', function (Blueprint $table): void {
            $table->index(['result', 'created_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('polar_webhook_events') || ! Schema::hasIndex('polar_webhook_events', ['result', 'created_at'])) {
            return;
        }

        Schema::table('polar_webhook_events', function (Blueprint $table): void {
            $table->dropIndex(['result', 'created_at']);
        });
    }
};
