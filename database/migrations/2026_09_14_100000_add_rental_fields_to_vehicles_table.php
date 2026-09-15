<?php

declare(strict_types=1);

use App\Modules\Core\Support\DbTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deja que un vehículo del patio también se pueda alquilar.
 *
 * `usage_type` por omisión queda en «sale»: los vehículos que ya existen siguen siendo solo de venta
 * hasta que alguien les marque precio de alquiler a propósito. Las tarifas y el límite de kilometraje
 * son nullable porque solo tienen sentido en una unidad que SÍ se alquila.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicles')) {
            return;
        }

        Schema::table('vehicles', function (Blueprint $table): void {
            if (! Schema::hasColumn('vehicles', 'usage_type')) {
                $table->string('usage_type')->default('sale')->after('status'); // sale, rental, both
            }

            if (! Schema::hasColumn('vehicles', 'rental_price_daily')) {
                $table->decimal('rental_price_daily', 15, 2)->nullable()->after('usage_type');
            }

            if (! Schema::hasColumn('vehicles', 'rental_price_weekly')) {
                $table->decimal('rental_price_weekly', 15, 2)->nullable()->after('rental_price_daily');
            }

            if (! Schema::hasColumn('vehicles', 'rental_price_monthly')) {
                $table->decimal('rental_price_monthly', 15, 2)->nullable()->after('rental_price_weekly');
            }

            if (! Schema::hasColumn('vehicles', 'deposit_amount')) {
                $table->decimal('deposit_amount', 15, 2)->nullable()->after('rental_price_monthly');
            }

            if (! Schema::hasColumn('vehicles', 'rental_km_limit_daily')) {
                $table->unsignedInteger('rental_km_limit_daily')->nullable()->after('deposit_amount');
            }

            if (! Schema::hasColumn('vehicles', 'extra_km_price')) {
                $table->decimal('extra_km_price', 15, 2)->nullable()->after('rental_km_limit_daily');
            }
        });

        DbTable::olvidar();
    }

    public function down(): void
    {
        if (! Schema::hasTable('vehicles')) {
            return;
        }

        Schema::table('vehicles', function (Blueprint $table): void {
            foreach ([
                'usage_type', 'rental_price_daily', 'rental_price_weekly', 'rental_price_monthly',
                'deposit_amount', 'rental_km_limit_daily', 'extra_km_price',
            ] as $columna) {
                if (Schema::hasColumn('vehicles', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        DbTable::olvidar();
    }
};
