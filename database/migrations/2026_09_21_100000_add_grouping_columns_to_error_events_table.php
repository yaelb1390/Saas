<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un error ya no es «de una empresa»: puede afectar a varias.
 *
 * `error_events` guarda cada error UNA vez, agrupado por huella. Hasta hoy la fila decía de qué empresa
 * era el error con `company_id`, pero un mismo fallo puede afectar a doce, y cada repetición pisaba ese
 * valor con el de la última: se perdía qué empresas lo sufrían y, si la última ocurrencia era de consola,
 * hasta la última conocida.
 *
 * Esta migración solo AÑADE columnas, todas con valor por omisión, así que las filas que ya hay siguen
 * siendo válidas sin tocarlas y el código anterior sigue funcionando:
 *
 *  · `status` (`active`, `resolved`, `ignored`) y sus fechas: hasta hoy un error era «activo» para siempre,
 *    porque no había forma de decir que ya se arregló.
 *  · `service` y `route_name`: de qué servicio o integración viene, y por qué ruta entró la última vez.
 *  · `fingerprint_version`: 1 para los grupos que ya existen (huella anterior, `sha1` de clase y línea) y
 *    2 para los nuevos. No se fusionan: no se puede recalcular una huella sin la excepción original.
 *  · `companies_count` y `users_count`: a cuántas empresas y usuarios afecta, para ordenar y contar sin
 *    recorrer las tablas hijas.
 *
 * El desglose por empresa y por usuario va en dos tablas hijas (ver las migraciones siguientes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('error_events')) {
            return;
        }

        Schema::table('error_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('error_events', 'status')) {
                // `active` | `resolved` | `ignored`. Un texto corto en vez de un enum de la base de datos.
                $table->string('status', 12)->default('active');
            }

            if (! Schema::hasColumn('error_events', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable();
            }

            if (! Schema::hasColumn('error_events', 'resolved_by')) {
                // Sin clave foránea: el usuario que lo resolvió puede dejar de existir.
                $table->unsignedBigInteger('resolved_by')->nullable();
            }

            if (! Schema::hasColumn('error_events', 'service')) {
                $table->string('service', 30)->nullable();
            }

            if (! Schema::hasColumn('error_events', 'route_name')) {
                $table->string('route_name', 150)->nullable();
            }

            if (! Schema::hasColumn('error_events', 'fingerprint_version')) {
                // 1 = la huella de antes (histórico). Los grupos nuevos se escriben con 2.
                $table->unsignedTinyInteger('fingerprint_version')->default(1);
            }

            if (! Schema::hasColumn('error_events', 'companies_count')) {
                $table->unsignedInteger('companies_count')->default(0);
            }

            if (! Schema::hasColumn('error_events', 'users_count')) {
                $table->unsignedInteger('users_count')->default(0);
            }
        });

        Schema::table('error_events', function (Blueprint $table): void {
            // La pantalla lista «los que siguen activos, por lo último que falló» y filtra por servicio.
            if (! Schema::hasIndex('error_events', ['status', 'last_seen_at'])) {
                $table->index(['status', 'last_seen_at']);
            }

            if (! Schema::hasIndex('error_events', ['service', 'last_seen_at'])) {
                $table->index(['service', 'last_seen_at']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('error_events')) {
            return;
        }

        Schema::table('error_events', function (Blueprint $table): void {
            foreach ([['status', 'last_seen_at'], ['service', 'last_seen_at']] as $columnas) {
                if (Schema::hasIndex('error_events', $columnas)) {
                    $table->dropIndex($columnas);
                }
            }
        });

        Schema::table('error_events', function (Blueprint $table): void {
            $columnas = array_values(array_filter(
                ['status', 'resolved_at', 'resolved_by', 'service', 'route_name', 'fingerprint_version', 'companies_count', 'users_count'],
                static fn (string $columna): bool => Schema::hasColumn('error_events', $columna),
            ));

            if ($columnas !== []) {
                $table->dropColumn($columnas);
            }
        });
    }
};
