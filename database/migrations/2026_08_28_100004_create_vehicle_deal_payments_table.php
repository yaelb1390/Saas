<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los abonos del cliente, aparte de las cuotas.
 *
 * Un abono y una cuota no son lo mismo y por eso van en tablas distintas: la cuota es lo que se
 * DEBE en una fecha, el abono es lo que se PAGÓ y cuándo. Un cliente abona 5 mil y eso puede saldar
 * una cuota y media; guardarlo dentro de la cuota perdería el rastro de cuándo entró el dinero y por
 * qué vía, que es lo que hay que enseñar cuando alguien reclama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_deal_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_deal_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('method')->default('cash'); // cash, transfer, card, check
            $table->string('reference')->nullable();
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'vehicle_deal_id']);
            $table->index(['company_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_deal_payments');
    }
};
