<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `purge_at`: momento en que deben borrarse los DATOS de una prueba self-service si no se contrató un
 * plan (24 h después de vencer la prueba). Es el único disparador del borrado automático: solo lo fija
 * el registro self-service; cualquier acción del operador (pago, cambio de plan) lo deja en NULL, de
 * modo que las pruebas dadas por el super admin nunca se auto-borran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('purge_at')->nullable()->after('cancelled_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('purge_at');
        });
    }
};
