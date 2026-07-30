<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca cuándo se envió el aviso de vencimiento del período vigente, para no reenviarlo. Se pone en
 * NULL al renovar/pagar (nuevo período) para que el siguiente vencimiento vuelva a avisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('renewal_reminded_at')->nullable()->after('purge_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('renewal_reminded_at');
        });
    }
};
