<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La clave de IA de la plataforma, para que se pueda pegar desde una pantalla.
 *
 * Hasta ahora salía solo de variables de entorno, así que encender la IA exigía tocar el `.env` de
 * Vercel y volver a desplegar. El operador quiere pegarla y que funcione.
 *
 * ES DE LA PLATAFORMA Y NO DE CADA EMPRESA. Un dueño de colmado no va a sacar una clave en Google AI
 * Studio; con Zernio ya se comprobó que ese paso lo da casi nadie. Aquí la pone el operador una vez y
 * todas las empresas con el módulo contratado empiezan a funcionar.
 *
 * TABLA PROPIA Y COLUMNA CIFRADA, no una entrada en un JSON de ajustes. Es el mismo razonamiento que
 * está escrito en la migración de la clave de Zernio: un JSON se lee entero en cada petición, se
 * vuelca en los volcados de depuración y acaba en cualquier exportación que alguien añada mañana sin
 * acordarse de que ahí dentro había un secreto.
 *
 * `text` y no `string`: el valor cifrado ocupa bastante más que la clave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->default('local');
            $table->text('api_key')->nullable();
            $table->string('chat_model')->nullable();
            $table->string('embedding_model')->nullable();
            // Se guarda el tamaño con el que se indexa, y no se hereda del modelo: si Google cambia
            // mañana lo que devuelve por omisión, todo lo ya indexado dejaría de casar en silencio.
            $table->unsignedSmallInteger('embedding_dimensions')->default(768);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
