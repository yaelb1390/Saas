<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une un identificador de Instagram con, opcionalmente, un cliente del CRM ya existente.
 *
 * Esta tabla es la pieza que `customers` no tiene hoy (auditoría, sección 6): el cliente solo
 * guarda un único `phone` y un único `email`, no una lista de identidades por canal. En vez de
 * forzar el usuario de Instagram dentro de esas columnas —lo que rompería el vínculo por
 * teléfono que ya usa WhatsApp— se enlaza aparte, sin tocar la tabla `customers`.
 *
 * `customer_id` empieza NULO: se enlaza cuando hay evidencia real (la persona da su teléfono al
 * pasar a WhatsApp, o un administrador lo enlaza a mano), nunca por coincidir el nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_commerce_contact_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('channel', 20)->default('instagram');
            // El identificador que manda Zernio para la persona (IGSID/PSID), no su @usuario:
            // el @usuario se puede cambiar, el identificador no.
            $table->string('external_id');
            $table->string('external_username')->nullable();
            $table->string('display_name')->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'channel', 'external_id']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_commerce_contact_identities');
    }
};
