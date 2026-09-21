<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El registro del sistema se puede buscar y filtrar por servicio.
 *
 * Hasta hoy el buscador solo miraba el mensaje, y para filtrar por servicio no había ni columna. Esta
 * migración añade:
 *
 *  · `service`: de qué servicio o integración habla el suceso (`polar`, `evolution`, `zernio`, `ai`,
 *    `auth`, `scheduler`…). Nula cuando no se sabe: es mejor no decir nada que decir uno falso.
 *  · Índices por servicio, por usuario y por IP. Buscar «todo lo de este usuario» o «todo lo de esta IP»
 *    sin índice es recorrer la tabla entera, y esa es justo la búsqueda que se hace cuando algo va mal.
 *
 * Y rellena `service` en los sucesos que ya hay, con unas pocas sentencias por tipo (la tabla se poda a
 * los 90 días, así que no es grande). El servicio de los sucesos nuevos lo calcula `ServiceResolver`.
 */
return new class extends Migration
{
    /** El servicio de cada familia de sucesos, por el prefijo de su tipo. Igual que `ServiceResolver`. */
    private const PREFIJOS = [
        'auth.%' => 'auth',
        'task.%' => 'scheduler',
        'mail.%' => 'mail',
        'subscription.%' => 'polar',
        'platform.%' => 'platform',
    ];

    /** Palabras del mensaje que delatan el servicio en los avisos de integración y de webhook. */
    private const PALABRAS = [
        'polar' => 'polar',
        'zernio' => 'zernio',
        'redes' => 'zernio',
        'bienvenida' => 'zernio',
        'sentimiento' => 'ai',
        'inteligencia' => 'ai',
        'whatsapp' => 'evolution',
        'evolution' => 'evolution',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('system_events')) {
            return;
        }

        if (! Schema::hasColumn('system_events', 'service')) {
            Schema::table('system_events', function (Blueprint $table): void {
                $table->string('service', 30)->nullable();
            });
        }

        Schema::table('system_events', function (Blueprint $table): void {
            foreach ([['service', 'created_at'], ['user_id', 'created_at'], ['ip', 'created_at']] as $columnas) {
                if (! Schema::hasIndex('system_events', $columnas)) {
                    $table->index($columnas);
                }
            }
        });

        foreach (self::PREFIJOS as $patron => $servicio) {
            DB::table('system_events')->whereNull('service')->where('type', 'like', $patron)->update(['service' => $servicio]);
        }

        // Los avisos de integración y de webhook no dicen su servicio en el tipo sino en el mensaje. El
        // primero que aparezca gana, en el mismo orden que `ServiceResolver::PALABRAS_DE_SUCESO`.
        foreach (self::PALABRAS as $palabra => $servicio) {
            DB::table('system_events')
                ->whereNull('service')
                ->where(function ($consulta): void {
                    $consulta->where('type', 'like', 'integration.%')->orWhere('type', 'like', 'webhook.%');
                })
                ->whereRaw('lower(message) like ?', ['%'.$palabra.'%'])
                ->update(['service' => $servicio]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_events')) {
            return;
        }

        Schema::table('system_events', function (Blueprint $table): void {
            foreach ([['service', 'created_at'], ['user_id', 'created_at'], ['ip', 'created_at']] as $columnas) {
                if (Schema::hasIndex('system_events', $columnas)) {
                    $table->dropIndex($columnas);
                }
            }
        });

        if (Schema::hasColumn('system_events', 'service')) {
            Schema::table('system_events', function (Blueprint $table): void {
                $table->dropColumn('service');
            });
        }
    }
};
