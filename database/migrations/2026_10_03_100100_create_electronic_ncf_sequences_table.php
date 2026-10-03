<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secuencias de e-NCF autorizadas por la DGII a cada empresa, por ambiente y tipo.
 *
 * Tabla aparte de `fiscal_sequences` (serie B en papel) a propósito: el tipo de NCF de la serie B se
 * usa en cuatro pantallas y cuatro validaciones de facturación; mezclar aquí los tipos E los haría
 * aparecer en esos desplegables y permitiría emitir un e-NCF por el circuito de papel.
 *
 * e-NCF = «E» + tipo (2) + secuencial de 10 dígitos [IT §7]: `bigInteger` porque 10 dígitos no
 * caben en un entero de 32 bits. Vence el 31 de diciembre del año siguiente a la autorización
 * [IT §7]; en pre-certificación las de tipo 32 y 34 no vencen [DT pp.6–7], de ahí que sea nulable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('electronic_ncf_sequences')) {
            Schema::create('electronic_ncf_sequences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('environment', 20);
                $table->unsignedTinyInteger('ecf_type');
                $table->unsignedBigInteger('range_from');
                $table->unsignedBigInteger('range_to');
                $table->unsignedBigInteger('next_number');
                $table->date('authorized_at')->nullable();
                $table->date('expires_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['company_id', 'environment', 'ecf_type', 'is_active'], 'ecf_seq_lookup');
            });
        }

        /*
         * Números que la DGII rechazó con `secuenciaUtilizada = false`: la DGII permite reutilizarlos
         * [DT p.24]. Se guardan aparte para no reescribir el contador de la secuencia, y cada uno se
         * reutiliza una sola vez (`reused_at`).
         */
        if (! Schema::hasTable('electronic_ncf_releases')) {
            Schema::create('electronic_ncf_releases', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('electronic_ncf_sequence_id')->constrained()->cascadeOnDelete();
                $table->string('environment', 20);
                $table->unsignedTinyInteger('ecf_type');
                $table->unsignedBigInteger('number');
                $table->string('reason', 500);
                $table->timestamp('reused_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'environment', 'ecf_type', 'number'], 'ecf_release_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_ncf_releases');
        Schema::dropIfExists('electronic_ncf_sequences');
    }
};
