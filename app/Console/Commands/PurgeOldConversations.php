<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Core\Support\DbTable;
use App\Modules\WhatsApp\Models\WaBotSetting;
use App\Modules\WhatsApp\Models\WaConversation;
use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Console\Command;

/**
 * Borra los mensajes de WhatsApp más viejos que los días que haya pedido cada empresa.
 *
 * POR QUÉ EXISTE: una conversación de WhatsApp es de todo menos anodina. Ahí hay teléfonos,
 * direcciones de entrega, a veces el número de una tarjeta que alguien escribió sin pensar. Guardarlo
 * para siempre no le sirve al negocio —nadie consulta el pedido de hace dos años— y convierte cada
 * copia de seguridad en un problema si un día se pierde. Menos datos guardados es menos que perder.
 *
 * CERO DÍAS NO BORRA NADA, y esa es la regla que manda sobre todo lo demás. El valor por omisión es
 * cero justamente para eso: nadie se encuentra con su historial borrado por una tarea que no encendió.
 * Quien pone noventa días sabe lo que va a pasar; quien no ha tocado nada, no.
 *
 * A mano: `php artisan conversaciones:purgar`. Con `--dias=` se prueba sin tocar la configuración.
 */
final class PurgeOldConversations extends Command
{
    protected $signature = 'conversaciones:purgar
                            {--dias= : Fuerza los días para todas las empresas, ignorando su ajuste}
                            {--simular : Cuenta lo que borraría sin borrar nada}';

    protected $description = 'Borra los mensajes de WhatsApp más viejos que la retención de cada empresa.';

    public function handle(): int
    {
        // Las migraciones aquí se aplican a mano y el despliegue no las corre. Entre que sale este
        // comando y alguien migra, la columna no está: se sale sin ruido en vez de tumbar el cron.
        if (! DbTable::existe('wa_messages') || ! DbTable::tieneColumna('wa_bot_settings', 'retention_days')) {
            $this->warn('Todavía no está la columna de retención. No hay nada que purgar.');

            return self::SUCCESS;
        }

        $forzados = $this->option('dias');
        $simular = (bool) $this->option('simular');
        $total = 0;

        $ajustes = WaBotSetting::withoutGlobalScopes()->get();

        foreach ($ajustes as $ajuste) {
            $dias = $forzados !== null ? (int) $forzados : (int) $ajuste->retention_days;

            /*
             * Cero —o cualquier disparate— no borra.
             *
             * El `<= 0` cubre el cero configurado y también un negativo que se hubiera colado: un
             * número de días negativo, restado a la fecha de hoy, daría un corte EN EL FUTURO y
             * borraría la conversación entera, incluido el mensaje que entró hace un minuto.
             */
            if ($dias <= 0) {
                continue;
            }

            $corte = now()->subDays($dias);
            $empresa = (int) $ajuste->company_id;

            $consulta = WaMessage::withoutGlobalScopes()
                ->where('company_id', $empresa)
                ->where('created_at', '<', $corte);

            $cuantos = $simular ? $consulta->count() : $consulta->delete();
            $total += $cuantos;

            if ($cuantos > 0) {
                $this->line("Empresa #{$empresa}: {$cuantos} mensajes de más de {$dias} días.");
            }

            if (! $simular) {
                $this->borrarConversacionesVacias($empresa);
            }
        }

        $this->info($simular
            ? "Se borrarían {$total} mensajes."
            : "Mensajes borrados: {$total}.");

        return self::SUCCESS;
    }

    /**
     * Se lleva también las conversaciones que se quedaron sin un solo mensaje.
     *
     * Sin esto la bandeja se llenaría de hilos vacíos: el nombre y el teléfono de un cliente sin nada
     * dentro. Es lo peor de las dos opciones —se conserva el dato personal, que era justo lo que se
     * quería soltar, y encima no sirve para nada—.
     *
     * Se comprueba que NO tenga mensajes en vez de mirar su fecha: una conversación vieja que sigue
     * teniendo mensajes recientes está viva, y borrarla se llevaría por delante lo que se acaba de
     * hablar.
     */
    private function borrarConversacionesVacias(int $empresa): void
    {
        $vacias = WaConversation::withoutGlobalScopes()
            ->where('company_id', $empresa)
            ->whereDoesntHave('messages')
            ->delete();

        if ($vacias > 0) {
            $this->line("Empresa #{$empresa}: {$vacias} conversaciones se quedaron vacías y se borraron.");
        }
    }
}
