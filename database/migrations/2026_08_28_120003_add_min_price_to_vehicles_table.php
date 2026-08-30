<?php

declare(strict_types=1);

use App\Modules\Core\Support\DbTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El precio mínimo y el tipo de vehículo.
 *
 * `min_price` es el suelo de la negociación: por debajo, el dealer pierde o gana tan poco que no vale
 * la pena. Se AVISA al pactar un precio menor, no se impide: quien vende sabe cuándo conviene soltar
 * una unidad parada, y un sistema que se lo prohíba acabará con los precios falseados para poder
 * cerrar.
 *
 * `vehicle_type` —sedán, jeepeta, camioneta, minibús— es como el cliente pregunta por teléfono, y hoy
 * no se puede ni filtrar por eso.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicles')) {
            return;
        }

        Schema::table('vehicles', function (Blueprint $table): void {
            if (! Schema::hasColumn('vehicles', 'min_price')) {
                $table->decimal('min_price', 15, 2)->nullable()->after('asking_price');
            }

            if (! Schema::hasColumn('vehicles', 'vehicle_type')) {
                $table->string('vehicle_type')->nullable()->after('trim');
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
            foreach (['min_price', 'vehicle_type'] as $columna) {
                if (Schema::hasColumn('vehicles', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
