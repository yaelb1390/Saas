<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula la cuenta de Google al usuario. Se guarda el `sub` (id estable de Google) la primera vez
 * que entra por Google, para reconocerlo aunque cambiara de correo y evitar suplantaciones por
 * coincidencia de correo. El emparejamiento inicial es por correo verificado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_id')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('google_id');
        });
    }
};
