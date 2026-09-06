<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las formas de pago de una venta, una fila por vía.
 *
 * POR QUÉ HACE FALTA UNA TABLA. Hasta ahora una venta guardaba UN solo `payment_method`, así que
 * cobrar mitad en efectivo y mitad con tarjeta era imposible: había que elegir una y mentir sobre la
 * otra. Y esa mentira no se quedaba en la pantalla — decidía cuánto entraba al cajón y en qué columna
 * del 607 se declaraba la venta ante la DGII.
 *
 * LA INVARIANTE, que sostiene todo lo demás: `SUM(amount) == sales.total`, siempre, para toda venta
 * con filas. El vuelto NUNCA está dentro de `amount`. Si eso se rompe, el cajón, la contabilidad y el
 * fisco dejan de cuadrar cada uno por su lado.
 *
 * DOS IMPORTES Y NO UNO, a propósito:
 *   - `amount`   responde «¿cuánto de esta venta se pagó así?» — lo que necesitan el 607 y la cuenta.
 *   - `tendered` responde «¿cuánto entregó el cliente?» — lo que necesita el recibo y el vuelto.
 * En una venta de 1.000 con tarjeta 400 y 700 en billetes: `amount` efectivo = 600, `tendered` = 700,
 * vuelto 100, y al cajón entran 600. Con una sola columna esa cuenta hay que rehacerla en cada sitio
 * que la necesite, y cada sitio que la rehaga es un sitio donde equivocarse.
 *
 * NO SE RELLENA EL HISTÓRICO. Las ventas anteriores no tendrán filas, y no hace falta: quien lee el
 * desglose sabe sintetizarlo desde la cabecera (`payment_method`, `paid`). Un `UPDATE` masivo sobre
 * la tabla más grande del sistema, aplicado a mano en producción, no compraría nada.
 *
 * OJO AL LEER: nadie consulta esta tabla directamente. Un informe que haga `JOIN sale_payments`
 * haría desaparecer de sus totales TODAS las ventas anteriores a esta migración, sin avisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            /*
             * `cascadeOnDelete` con el borrado LÓGICO de ventas quiere decir que anular una venta NO
             * borra sus pagos: solo los borraría un borrado real, que es lo que hace el borrado de una
             * empresa entera. Y así debe ser — el desglose de una venta anulada sigue explicando por
             * qué salieron esos pesos del cajón.
             */
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            // Valores de PaymentMethod: cash, card, transfer, check, credit.
            $table->string('method');
            // La porción del TOTAL imputada a esta vía. Nunca incluye vuelto.
            $table->decimal('amount', 15, 2);
            // Lo que entregó el cliente por esta vía. Solo el efectivo puede superar a `amount`.
            $table->decimal('tendered', 15, 2)->default(0);
            // Voucher de la tarjeta, número de transferencia, número de cheque.
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'sale_id']);
            // Para el 607 y para cualquier informe por forma de cobro.
            $table->index(['company_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
