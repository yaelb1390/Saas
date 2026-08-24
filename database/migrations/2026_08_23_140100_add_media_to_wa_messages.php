<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hace falta para mandar un archivo por WhatsApp, y no solo texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_messages', function (Blueprint $table): void {
            /*
             * De dónde baja el archivo el proveedor.
             *
             * Se guarda la DIRECCIÓN y no el archivo. Evolution acepta una URL en `sendMedia` —está
             * comprobado contra su servidor: «media must be a url or base64»—, así que se le pasa el
             * enlace firmado y él lo descarga. Guardar el PDF sería imposible en producción, donde el
             * disco es de solo lectura, y además innecesario: se genera al vuelo cada vez, así que
             * nunca se queda viejo.
             */
            $table->string('media_url', 2048)->nullable()->after('body');

            /*
             * Cómo debe llamarse al llegar.
             *
             * WhatsApp enseña este nombre en el chat. Sin él, el cliente recibiría algo como
             * «document.pdf» y no sabría cuál de las tres cotizaciones que pidió es esta.
             */
            $table->string('media_name')->nullable()->after('media_url');
        });
    }

    public function down(): void
    {
        Schema::table('wa_messages', fn (Blueprint $t) => $t->dropColumn(['media_url', 'media_name']));
    }
};
