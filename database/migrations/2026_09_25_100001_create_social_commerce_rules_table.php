<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La regla palabra clave → producto → precio → plantilla.
 *
 * No guarda el texto final de las respuestas (eso vive en `social_commerce_rule_templates`, con
 * las variables sin resolver): esta tabla es la configuración y el estado de sincronización con
 * Zernio, que es quien de verdad ejecuta la automatización (ver arquitectura, sección 5).
 *
 * `product_id` referencia `products` (módulo Inventory) SIN tocar esa tabla ni ese módulo: es una
 * llave foránea de solo lectura, igual que `sale_items.product_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_commerce_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name', 80);

            // Cuenta de Zernio (Instagram) a la que se cuelga la regla. Es el identificador de
            // Zernio, no una fila local: las cuentas no se copian aquí (mismo principio que
            // `ZernioClient`: «Zernio es el registro»).
            $table->string('zernio_account_id');

            $table->string('trigger', 20)->default('comment'); // comment | story_reply

            // Publicación concreta, o ninguna (vale para cualquiera). Los DOS identificadores que
            // exige Zernio: el suyo y el de la red (ver StoreAutomationRequest::paraZernio()).
            $table->string('zernio_post_id')->nullable();
            $table->string('platform_post_id')->nullable();

            // No se puede borrar un producto con una regla activa apuntándole: mismo criterio que
            // `sale_items.product_id` (restrictOnDelete, no cascade).
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('price_mode', 20)->default('normal'); // normal | promotional

            // Palabras clave tal como las espera Zernio: un array, no una tabla aparte. Zernio no
            // desglosa estadísticas por palabra individual, así que normalizar más no aportaría
            // nada que no se pueda leer directamente de aquí.
            $table->json('keywords');
            $table->string('match_mode', 20)->default('word'); // word | exact | contains
            $table->boolean('typo_tolerance')->default(false);

            $table->boolean('also_in_dms')->default(false);
            $table->boolean('follow_gate')->default(false);
            $table->unsignedInteger('dm_delay_seconds')->default(0);

            $table->string('button_title', 20)->nullable();
            $table->string('button_url', 2000)->nullable();

            $table->string('status', 20)->default('draft'); // draft | active | paused | error

            // Lo que devuelve Zernio al crear la automatización, para poder editarla/borrarla
            // después. Null mientras no se haya sincronizado ni una vez.
            $table->string('zernio_automation_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('sync_error')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_commerce_rules');
    }
};
