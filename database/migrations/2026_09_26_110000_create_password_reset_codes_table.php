<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Códigos de 6 dígitos para recuperar la contraseña, en vez del enlace clicable de Fortify.
 *
 * Misma forma que la tabla estándar `password_reset_tokens` (una fila por correo, se sobrescribe
 * al pedir uno nuevo) — esa tabla se deja tal cual, sin usar, porque ya no pasa por el broker de
 * contraseñas de Laravel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_codes', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_codes');
    }
};
