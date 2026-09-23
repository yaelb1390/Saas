<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántas veces ocurrió un error en los últimos 15 minutos: la racha, no el total histórico.
 *
 * `hits` es el total desde siempre; un error visto una vez al mes durante un año también acumula un
 * número alto sin que eso signifique nada AHORA. `recent_hits` es lo que de verdad dice «esto se está
 * disparando»: `ErrorRecorder` lo reinicia a 1 cada vez que la última ocurrencia fue hace más de 15
 * minutos, y lo suma en caso contrario, en la MISMA sentencia que ya actualiza `hits` (sin una
 * consulta aparte). Lo usa `IncidentDetector` para la auto-apertura por racha (Fase 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('error_events') || Schema::hasColumn('error_events', 'recent_hits')) {
            return;
        }

        Schema::table('error_events', function (Blueprint $table): void {
            $table->unsignedInteger('recent_hits')->default(0);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('error_events') && Schema::hasColumn('error_events', 'recent_hits')) {
            Schema::table('error_events', function (Blueprint $table): void {
                $table->dropColumn('recent_hits');
            });
        }
    }
};
