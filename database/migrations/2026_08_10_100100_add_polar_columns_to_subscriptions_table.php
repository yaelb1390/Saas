<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza la suscripción de la app con la de Polar.
 *
 * Hace falta para los avisos POSTERIORES a la compra. En el primer pago la empresa se identifica por
 * los datos del checkout, pero cuando meses después llegue una baja o una renovación, el aviso solo
 * trae los identificadores de Polar. Sin guardarlos aquí, ese aviso no sabría a qué suscripción de
 * la app aplicar y una baja quedaría sin efecto: el cliente dejaría de pagar y seguiría entrando.
 *
 * `polar_subscription_id` es único: dos suscripciones de la app no pueden colgar de la misma de
 * Polar, o una baja apuntaría a dos sitios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('polar_subscription_id')->nullable()->unique()->after('plan_id');
            $table->string('polar_customer_id')->nullable()->index()->after('polar_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['polar_subscription_id', 'polar_customer_id']);
        });
    }
};
