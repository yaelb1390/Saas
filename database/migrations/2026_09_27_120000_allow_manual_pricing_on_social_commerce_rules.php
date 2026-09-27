<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una regla puede tener un producto del inventario O un nombre+precio puestos a mano (para algo
 * que se vende por Instagram pero no está en Inventario) — nunca las dos cosas ni ninguna, eso lo
 * exige `StoreRuleRequest`. `product_id` deja de ser obligatorio para permitirlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE social_commerce_rules ALTER COLUMN product_id DROP NOT NULL');

        Schema::table('social_commerce_rules', function (Blueprint $table): void {
            $table->string('manual_name', 120)->nullable()->after('product_id');
            // Misma precisión que products.price (database/migrations/2026_07_10_030001_create_products_table.php).
            $table->decimal('manual_price', 15, 2)->nullable()->after('manual_name');
        });
    }

    public function down(): void
    {
        Schema::table('social_commerce_rules', function (Blueprint $table): void {
            $table->dropColumn(['manual_name', 'manual_price']);
        });

        DB::statement('ALTER TABLE social_commerce_rules ALTER COLUMN product_id SET NOT NULL');
    }
};
