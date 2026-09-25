<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7: `CompanyHealthService::ultimaVenta()` agrupa por `company_id` y ordena por `created_at` de
 * TODAS las empresas a la vez —a propósito, sin el ámbito de tenant—. `sales` ya tenía índices con
 * `company_id`, pero ninguno con `created_at`: cada apertura de «Empresas» barría la tabla entera.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table): void {
            if (! Schema::hasIndex('sales', ['company_id', 'created_at'])) {
                $table->index(['company_id', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table): void {
            if (Schema::hasIndex('sales', ['company_id', 'created_at'])) {
                $table->dropIndex(['company_id', 'created_at']);
            }
        });
    }
};
