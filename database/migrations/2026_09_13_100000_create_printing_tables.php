<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El Centro de Impresión: impresoras registradas, sus plantillas, el historial de trabajos y la
 * preferencia de cada usuario.
 *
 * ================================================================================================
 * POR QUÉ NO HAY UNA SOLA IMPRESORA «DE LA EMPRESA».
 *
 * Un negocio real tiene varias: la térmica de 80mm en la caja, la de etiquetas en el almacén, quizás
 * una A4 en la oficina para las facturas. Por eso `printers` es un catálogo por empresa, y cada
 * módulo (Ventas, Facturación, Etiquetas…) apunta a la suya en `companies.settings` — no aquí, para
 * no crear una tabla puente solo por eso; `settings` ya es donde viven los interruptores que van y
 * vienen (ver Company::feature()).
 *
 * Y CADA USUARIO TIENE LA SUYA. La térmica está físicamente atornillada a una caja, no a la empresa
 * entera: el cajero de la caja 2 no debe imprimir en la impresora de la caja 1 solo por compartir
 * negocio. De ahí `printer_preferences`, una fila por usuario.
 *
 * BLUETOOTH NO SE PUEDE BUSCAR EN SILENCIO desde una web: el navegador exige que la persona elija
 * el dispositivo en su propio selector. Lo que persiste aquí (`bt_device_id`) es el identificador que
 * ese selector entrega, para poder reconectar sin pedirlo de nuevo cada vez.
 * ================================================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Quién la registró. Informativo, no cambia quién puede usarla: eso lo rige el permiso.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();

            // bluetooth | usb | network | browser. El navegador es la impresora del sistema
            // operativo: la que ya se elige en el diálogo de imprimir, sin nada que emparejar aquí.
            $table->string('connection_type', 20);

            // Solo Bluetooth: lo que Web Bluetooth necesita para reconectar sin pedir el selector de
            // nuevo. UUIDs de servicio y característica porque cada fabricante los expone distinto.
            $table->string('bt_device_id')->nullable();
            $table->string('bt_service_uuid')->nullable();
            $table->string('bt_characteristic_uuid')->nullable();

            // Solo red: se teclea a mano (IP:puerto). Una web no puede escanear la LAN.
            $table->string('address')->nullable();

            // Clave del catálogo de PaperSize (58mm, 80mm, carta, a4, custom...).
            $table->string('paper_size', 20)->default('80mm');

            // Ancho y alto en mm cuando paper_size = 'custom'. Null en cualquier otro caso.
            $table->unsignedSmallInteger('custom_width_mm')->nullable();
            $table->unsignedSmallInteger('custom_height_mm')->nullable();

            /*
             * El resto de la configuración de la impresora: márgenes, orientación, calidad, copias
             * por omisión, corte automático, color. Un JSON y no columnas propias porque son ajustes
             * de hardware que varían por fabricante: obligar una columna por opción futura
             * significaría una migración cada vez que aparece una impresora con un capricho nuevo.
             */
            $table->json('settings')->nullable();

            // disponible | conectada | desconectada | error. Se actualiza al usarla, no en vivo: una
            // web no sondea hardware en segundo plano.
            $table->string('last_status', 20)->default('disponible');
            $table->timestamp('last_seen_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'connection_type']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('print_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Clave del catálogo DocumentType (sale_ticket, invoice_a4, daily_report...).
            $table->string('document_type', 40);
            $table->string('name');
            $table->string('paper_size', 20)->default('80mm');

            /*
             * El diseño entero en un JSON: qué campos se muestran (logo, teléfono, dirección, RNC,
             * NCF...), su tamaño de fuente y alineación, el texto de encabezado y pie, y si lleva QR
             * o código de barras. Es lo que pinta el editor visual y lo que lee DocumentRenderer: un
             * solo contrato para los dos lados, sin traducir de columnas a JSON y de vuelta.
             */
            $table->json('layout');

            // Una plantilla por defecto POR TIPO de documento, no una global. La factura A4 y el
            // ticket de venta no compiten por ser la default: cada tipo tiene la suya.
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['company_id', 'document_type']);
        });

        Schema::create('print_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null si se imprimió por el diálogo del navegador sin una impresora registrada.
            $table->foreignId('printer_id')->nullable()->constrained('printers')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('print_templates')->nullOnDelete();

            $table->string('document_type', 40);
            // El documento de origen (la venta, el préstamo...), si lo hay. Morph porque el
            // historial es transversal a todos los módulos de BMIA, no solo a uno.
            $table->nullableMorphs('reference');

            $table->unsignedSmallInteger('copies')->default(1);
            $table->string('paper_size', 20)->nullable();

            // printed | error | canceled.
            $table->string('status', 20);
            $table->string('error_message')->nullable();

            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            // Los cuatro filtros que pide la pantalla de historial: fecha (created_at), usuario,
            // impresora, tipo de documento.
            $table->index(['company_id', 'user_id']);
            $table->index(['company_id', 'printer_id']);
            $table->index(['company_id', 'document_type']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('printer_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('default_printer_id')->nullable()->constrained('printers')->nullOnDelete();
            $table->timestamps();

            // Una preferencia por usuario dentro de la empresa, no por usuario a secas: un mismo
            // dueño con dos negocios puede preferir impresoras distintas en cada uno.
            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_preferences');
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('print_templates');
        Schema::dropIfExists('printers');
    }
};
