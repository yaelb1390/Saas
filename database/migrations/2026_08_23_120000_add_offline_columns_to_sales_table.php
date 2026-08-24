<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hace falta para cobrar sin internet y subir la venta después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            /*
             * La llave que impide cobrar dos veces al mismo cliente.
             *
             * La pone el NAVEGADOR (crypto.randomUUID) en el momento del cobro, antes de que exista
             * conexión, y viaja con la venta cuando por fin se sube. Es lo único que permite
             * reintentar sin miedo: si el envío se cortó a medias y no se sabe si llegó, se vuelve a
             * mandar y el servidor reconoce la que ya tiene.
             *
             * No sirve el `code` de la venta para esto: se genera en el servidor contando las ventas
             * de la empresa, así que dos terminales sin línea llegarían con el mismo número.
             *
             * Nula para todo lo cobrado con conexión, que es la inmensa mayoría y no necesita llave.
             * Única por empresa —no global— porque el aislamiento entre empresas es la regla de la
             * casa: un UUID repetido entre dos negocios distintos no es un problema de nadie.
             */
            $table->uuid('client_uuid')->nullable()->after('code');

            /*
             * Cuándo se subió una venta que se cobró sin línea.
             *
             * Con `created_at` no basta: al insertarse ahora, una venta de ayer por la tarde parecería
             * de esta mañana, y el arqueo de caja no cuadraría con lo que dice el cajero. Aquí queda
             * la marca de que esa venta vivió un rato solo en un teléfono.
             */
            $table->timestamp('synced_offline_at')->nullable()->after('completed_at');

            /*
             * Esta venta entró aceptándole algo al terminal, y alguien debería mirarla.
             *
             * Se marca cuando el precio cobrado ya no es el del catálogo, cuando el stock no daba y
             * quedó en negativo, o cuando la caja contra la que se cobró ya estaba cerrada. La venta
             * ENTRA igual —el cliente ya pagó y tiene su recibo—, pero no entra en silencio: el
             * descuadre existe y esconderlo solo retrasa el momento de descubrirlo.
             */
            $table->text('offline_review')->nullable()->after('synced_offline_at');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->unique(['company_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'client_uuid']);
            $table->dropColumn(['client_uuid', 'synced_offline_at', 'offline_review']);
        });
    }
};
