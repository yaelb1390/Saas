<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula usuarios a una empresa (tenant) y marca super administradores de plataforma.
 * Un super admin puede operar fuera del aislamiento por company_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('company_id')
                ->constrained()->nullOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('branch_id');
            $table->boolean('is_active')->default(true)->after('is_super_admin');

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['is_super_admin', 'is_active']);
        });
    }
};
