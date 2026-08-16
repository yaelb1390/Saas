<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La clave de Zernio de cada empresa, para publicar en sus redes sociales.
 *
 * COLUMNA PROPIA Y CIFRADA, no una entrada más en `settings`. Es una credencial: quien la tenga
 * puede publicar en el Instagram del cliente y leer sus mensajes. `settings` es un JSON que se lee
 * entero en cada petición, se vuelca en los volcados de depuración y acaba en cualquier exportación
 * que alguien añada mañana sin acordarse de que ahí dentro había un secreto.
 *
 * `text` y no `string`: el valor cifrado ocupa bastante más que los 71 caracteres de la clave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->text('social_api_key')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('social_api_key');
        });
    }
};
