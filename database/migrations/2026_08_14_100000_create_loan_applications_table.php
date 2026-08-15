<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de préstamo: el expediente de cómo nació cada préstamo.
 *
 * Hasta ahora un préstamo aparecía de la nada —alguien abría el modal, escribía el capital y el
 * dinero salía de la caja en el mismo acto—, sin rastro de quién lo pidió, con qué ingresos, con qué
 * garante ni quién lo aprobó. Para una agencia de préstamos eso no es una comodidad que falta: es el
 * expediente del negocio.
 *
 * La tabla tiene tres bloques y conviene leerla así:
 *
 *  1. Lo que PIDE el cliente. Misma forma que `CreateLoanData` a propósito: al desembolsar se vuelca
 *     tal cual en el motor de préstamos, que no se toca.
 *  2. La EVALUACIÓN. Va aquí y no en una tabla aparte porque una solicitud tiene una evaluación y
 *     punto; separarla obligaría a un join en todas las pantallas para no ganar nada.
 *  3. La DECISIÓN, con los términos aprobados aparte de los pedidos.
 *
 * Los `approved_*` existen porque el caso normal es aprobar MENOS de lo pedido. Guardarlos aparte
 * deja a la vista «pidió 50.000, le aprobamos 30.000» —justo lo que un prestamista quiere ver— y
 * evita perder la petición original. Al desembolsar se usa `approved_x ?? x`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('customer_name')->nullable();        // snapshot del nombre al solicitar

            // ---- Lo que pide el cliente (congelado en cuanto se decide) ----------------------
            $table->decimal('principal', 15, 2);                // capital solicitado
            $table->decimal('interest_rate', 8, 2)->default(0); // % plano
            $table->decimal('interest_amount', 15, 2)->nullable(); // si se escribe, manda sobre la tasa
            $table->string('frequency');                        // daily, weekly, biweekly, monthly
            $table->unsignedSmallInteger('installments_count');
            $table->decimal('late_fee_rate', 8, 2)->nullable();
            $table->date('start_date');                         // primer vencimiento previsto
            $table->text('collateral')->nullable();             // garantía ofrecida
            $table->text('purpose')->nullable();                // para qué lo quiere
            $table->text('notes')->nullable();

            // ---- Evaluación --------------------------------------------------------------------
            // Nullable de arriba abajo: una solicitud se recibe primero y se evalúa después, a
            // veces en otra visita. Exigir los ingresos para poder guardarla obligaría a
            // inventárselos en el mostrador.
            $table->decimal('monthly_income', 15, 2)->nullable();
            $table->decimal('monthly_expenses', 15, 2)->nullable();
            $table->decimal('other_debts', 15, 2)->nullable();  // cuotas mensuales de otras deudas
            $table->string('employment')->nullable();           // dónde trabaja o a qué se dedica
            $table->string('guarantor_name')->nullable();
            $table->string('guarantor_phone', 50)->nullable();
            $table->string('guarantor_cedula', 20)->nullable();
            $table->text('evaluation_notes')->nullable();

            // ---- Decisión ----------------------------------------------------------------------
            $table->string('status')->default('received');
            $table->decimal('approved_principal', 15, 2)->nullable();
            $table->unsignedSmallInteger('approved_installments_count')->nullable();
            $table->decimal('approved_interest_rate', 8, 2)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_notes')->nullable();

            // El préstamo que salió de esta solicitud. Es el enlace del expediente en los dos
            // sentidos y, de paso, la prueba de que no se desembolsó dos veces.
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // quien la recibió
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_applications');
    }
};
