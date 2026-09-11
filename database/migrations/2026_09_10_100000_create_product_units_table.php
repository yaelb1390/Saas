<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las UNIDADES de un producto serializado: cada una con su número de serie propio.
 *
 * ================================================================================================
 * POR QUÉ ESTO NO ROMPE EL STOCK QUE YA HAY.
 *
 * Hasta ahora el inventario de BMIA es CUANTITATIVO: la tabla `stock` guarda «de este producto hay
 * 20 en este almacén», y las 20 son idénticas. Sirve para una batida o un tornillo, donde una unidad
 * no se distingue de la de al lado.
 *
 * No sirve para un celular o un electrodoméstico, donde importa CUÁL es cada uno: su serie, su
 * condición, cuál se vendió. Para eso está esta tabla.
 *
 * PERO LAS DOS COSAS CONVIVEN, y esa es la decisión que sostiene todo el módulo: una unidad, al
 * crearse, TAMBIÉN suma al `stock` por cantidad, por la misma puerta de siempre (`StockService`, con
 * su kardex). Al venderse, resta. Asi:
 *
 *   · El `stock` sigue siendo la verdad de CUÁNTAS hay. El POS, los informes y el kardex lo leen
 *     igual que antes y no se enteran de que existe este módulo.
 *   · Las unidades añaden CUÁLES son, encima, sin ser un segundo sistema de stock que pueda
 *     descuadrar con el primero.
 *
 * Serializar es OPCIONAL POR PRODUCTO (`products.tracks_serials`). Una cafetería no marca ninguno; una
 * tienda de electrónica marca los que lo necesitan. Obligar a serializar un café seria el modelo mal
 * aplicado.
 * ================================================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        // El interruptor por producto. Apagado por omisión: la inmensa mayoría no se serializa.
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'tracks_serials')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('tracks_serials')->default(false)->after('track_stock');
            });
        }

        if (Schema::hasTable('product_units')) {
            return;
        }

        Schema::create('product_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // En qué almacén está físicamente. Una unidad está en UN sitio, no repartida.
            $table->foreignId('warehouse_id')->constrained();

            // La identidad. Genérico a propósito: vale un IMEI, un nº de serie o un VIN. Lo que no
            // vale es que se repita dentro de la empresa — es lo que hace única a la unidad.
            $table->string('serial');

            /*
             * El estado, que es lo que de verdad gobierna la unidad.
             *
             *   available → está y se puede vender. Es la que cuenta como stock.
             *   reserved  → apartada para un cliente.
             *   sold      → salió. Deja de contar como stock, pero NO se borra: su historia
             *               —quién la compró, con qué serie— es justo lo que se querrá consultar
             *               cuando el cliente vuelva con la garantía.
             *   returned  → volvió tras una devolución.
             */
            $table->string('status', 20)->default('available');

            // Lo que distingue una unidad de otra del mismo modelo. Todo opcional: un producto de
            // serie sin más se da de alta solo con el serial.
            $table->string('condition', 20)->nullable();   // nuevo, usado, reacondicionado…
            $table->string('color')->nullable();

            /*
             * Costo y precio PROPIOS de la unidad, no del modelo.
             *
             * Dos iPhone iguales comprados con meses de diferencia cuestan distinto, y el usado se
             * vende a otro precio que el nuevo. Nulo quiere decir «el del producto»: no se copia el
             * del modelo aquí para que, si el precio del catálogo cambia, la unidad sin precio propio
             * siga aquel y no uno congelado.
             */
            $table->decimal('cost', 15, 2)->nullable();
            $table->decimal('price', 15, 2)->nullable();

            $table->string('notes')->nullable();

            // Cuándo entró y cuándo salió: para el informe de «cuánto tiempo estuvo en la estantería».
            $table->timestamp('received_at')->nullable();
            $table->timestamp('sold_at')->nullable();
            // La venta por la que salió, si salió. Null mientras está disponible.
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // El serial es único DENTRO de la empresa, no en todo el sistema: dos tiendas distintas
            // pueden tener, cada una, el suyo — y de hecho un mismo aparato reacondicionado podría
            // reaparecer. La unicidad por empresa es la que de verdad importa.
            $table->unique(['company_id', 'serial']);
            // Para «cuántas disponibles de este producto en este almacén», que es la pregunta caliente.
            $table->index(['company_id', 'product_id', 'warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'tracks_serials')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('tracks_serials');
            });
        }
    }
};
