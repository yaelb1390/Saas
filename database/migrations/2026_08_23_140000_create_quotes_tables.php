<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotizaciones: lo que se le OFRECE a un cliente antes de venderle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code');

            /*
             * A quién se cotiza: al cliente del CRM, o a alguien que solo pidió un precio.
             *
             * Las tres columnas conviven a propósito. `customer_id` engancha la cotización al
             * historial del cliente cuando existe; el nombre y el teléfono se guardan SIEMPRE, y
             * además copiados, porque una cotización es un documento con fecha: si mañana cambia el
             * teléfono de la ficha, lo que se ofertó aquel día seguía yendo al número de aquel día.
             */
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();

            $table->string('status')->default('draft');

            /*
             * Hasta cuándo vale lo ofertado.
             *
             * Es la razón de ser de una cotización frente a una lista de precios: un compromiso CON
             * FECHA. Sin ella, un cliente puede aparecer en marzo con el precio de enero y no hay
             * nada escrito que diga que ya no vale.
             */
            $table->date('valid_until')->nullable();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->text('notes')->nullable();

            /*
             * La venta que salió de aquí, si se convirtió.
             *
             * Es la llave que impide cobrar dos veces la misma cotización: si ya hay venta, no se
             * crea otra. Un doble clic o un reintento son el caso normal, no el raro.
             */
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();

            /*
             * NULO a propósito.
             *
             * Media cotización de una ferretería o un taller es mano de obra, instalación o
             * transporte, que no están en el catálogo ni deben estarlo. Obligar a crear un producto
             * para poder cotizar un servicio llenaría el inventario de cosas que no son inventario
             * y que nadie va a contar nunca.
             */
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            /*
             * El texto de la línea, copiado al cotizar.
             *
             * Aunque haya producto: si mañana lo renombran, la cotización tiene que seguir diciendo
             * lo que decía cuando el cliente la leyó.
             */
            $table->string('description');

            $table->decimal('quantity', 15, 3)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['company_id', 'quote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
    }
};
