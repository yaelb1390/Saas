<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            // Cuántas preguntas de IA al día admite este plan. `null` = usa el tope de la
            // plataforma (Administración › IA), igual que `max_users`/`max_branches` en null
            // significan «sin tope propio».
            $table->unsignedInteger('ai_daily_limit')->nullable()->after('max_branches');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('ai_daily_limit');
        });
    }
};
