<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulos contratados por cada empresa (el plan del SaaS).
 *
 * NULL significa «todos los módulos» — así las empresas ya existentes conservan el comportamiento
 * anterior sin migración de datos. Un array de claves restringe la empresa a esos módulos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('modules')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('modules');
        });
    }
};
