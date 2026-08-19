<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántas preguntas al día puede hacer cada empresa al asistente.
 *
 * Un solo número para toda la plataforma, no uno por empresa: el problema que resuelve es «que nadie
 * se dispare», y para eso basta un techo común. Un tope por empresa serían quince sitios donde mirar
 * cuando alguien diga «se me acabó» y ningún beneficio hasta que haya un cliente que de verdad
 * necesite más que los demás.
 *
 * 50 por omisión. Es holgado para una empresa que pregunta de verdad —son dudas de uso, no un
 * chat de rato— y corta en seco un bucle accidental o a quien se ponga a jugar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->unsignedInteger('daily_limit')->default(50)->after('embedding_dimensions');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('daily_limit');
        });
    }
};
