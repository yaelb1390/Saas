<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cédula del cliente (documento de identidad personal, distinto del RNC de una empresa). Clave para
 * el negocio de préstamos: se identifica a la persona a la que se le presta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('cedula')->nullable()->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('cedula');
        });
    }
};
