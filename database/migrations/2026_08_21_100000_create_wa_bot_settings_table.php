<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El bot que atiende a los clientes por WhatsApp.
 *
 * `business_info` es TEXTO LIBRE y no una base de conocimiento troceada, y es la decisión que más
 * simplifica todo esto. Lo que un negocio necesita contar —horario, si hay delivery, formas de pago,
 * política de cambios— son doscientas palabras: caben enteras en cada consulta al proveedor.
 *
 * El troceado con embeddings (`RagService`) existe para corpus grandes; aquí solo añadiría coste de
 * indexado, el problema de fragmentos que se quedan viejos —que ya tenemos— y una dependencia del
 * módulo `ai`, que una empresa puede no tener contratado aunque sí tenga WhatsApp.
 *
 * Y hay un motivo más: en todo el sistema NO EXISTE ningún concepto de horario comercial. Este campo
 * es hoy el único sitio donde esa información puede vivir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_bot_settings', function (Blueprint $table): void {
            $table->id();
            // Un bot por empresa: no son varios con reglas distintas, es «cómo atiende este negocio».
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(false);
            // Lo que el bot sabe del negocio. Sin tope duro en la base: el tope real lo pone el
            // formulario, y ahí se puede cambiar sin una migración.
            $table->text('business_info')->nullable();
            // Cómo se presenta. Va aparte del resto porque es lo primero que lee un cliente y quien
            // lo escribe quiere verlo suelto, no perdido dentro de un bloque largo.
            $table->string('greeting', 500)->nullable();

            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_bot_settings');
    }
};
