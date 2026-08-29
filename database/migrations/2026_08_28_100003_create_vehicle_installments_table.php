<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las cuotas del financiamiento propio del dealer.
 *
 * Tabla aparte de `loan_installments` a propósito: el dealer financia sin tener contratado el módulo
 * de Préstamos, y colgarle sus ventas a cuotas de aquel módulo lo obligaría a pagar por algo que no
 * pidió.
 *
 * Las columnas son las mismas —capital, interés, mora y abonado por cuota— porque el reparto es el
 * mismo, y el CÁLCULO se comparte de verdad: ambos usan `PlanDeCuotas`. Lo que no se comparte es la
 * tabla ni el módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_deal_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->decimal('principal_portion', 15, 2)->default(0);
            $table->decimal('interest_portion', 15, 2)->default(0);
            // La mora la pone el administrador cuota por cuota, como en préstamos: no se calcula
            // sola. En este negocio se negocia, y un recargo automático que nadie decidió acaba
            // discutido en el mostrador.
            $table->decimal('late_fee', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->string('status')->default('pending'); // pending, partial, paid
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_deal_id', 'number']);
            $table->index(['company_id', 'status']);
            // Para «¿quién me debe esta semana?», que es la pregunta de todos los lunes.
            $table->index(['company_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_installments');
    }
};
