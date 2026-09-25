<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué plantilla se usó y cuándo, inferido DESPUÉS del hecho.
 *
 * No es un mecanismo de control de la rotación (Zernio no lo permite, ver
 * SOCIAL_COMMERCE_META_RESEARCH.md): se rellena leyendo el webhook `message.received` con
 * dirección saliente y emparejando el texto recibido contra las plantillas configuradas. Sirve
 * para el panel de reportes, no para decidir nada.
 *
 * `rule_id`/`rule_template_id` en NULO al borrar: el reporte de lo que ya pasó no debe desaparecer
 * porque alguien edite o borre la regla después (mismo principio que «el recibo no muta» de Ventas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_commerce_rule_template_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('social_commerce_rules')->nullOnDelete();
            $table->foreignId('rule_template_id')->nullable()->constrained('social_commerce_rule_templates')->nullOnDelete();

            $table->string('channel', 10); // dm | public
            $table->text('matched_text');
            $table->timestamp('used_at');

            $table->timestamps();

            $table->index(['company_id', 'rule_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_commerce_rule_template_usage');
    }
};
