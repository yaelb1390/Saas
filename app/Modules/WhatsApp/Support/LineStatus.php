<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Support;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\WhatsApp\Gateways\WhatsAppConnection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * El estado de la línea, sin machacar a Evolution.
 *
 * La bandeja se sondea cada 4 segundos, y el estado tiene que viajar en ese sondeo para que la
 * pantalla se entere sola de que ya escaneaste el QR —antes decía «Recarga esta página»—. Pero
 * preguntárselo a Evolution cada 4 segundos, por pestaña abierta, es una llamada de red con su
 * plazo de espera metida dentro de una petición que debe ser instantánea.
 *
 * Con diez segundos de caché son seis llamadas por minuto y por empresa, abra quien abra las
 * pestañas que abra. Y diez segundos no se notan escaneando un QR: entre que apuntas el móvil y
 * WhatsApp confirma pasa más que eso.
 *
 * Lo que hace que no se noten NADA es {@see self::olvidar()}: cuando llega `CONNECTION_UPDATE`
 * se tira la caché, así que el siguiente sondeo trae el estado nuevo al instante. El sondeo es la
 * verdad; el webhook solo la adelanta.
 */
final class LineStatus
{
    private const SEGUNDOS = 10;

    public function __construct(
        private readonly WhatsAppConnection $connection,
        private readonly CurrentCompany $currentCompany,
    ) {}

    /**
     * @return array{state: string, instance: string, connected: bool, label: string, tono: string, pulso: bool, pista: string, badge: string}
     */
    public function actual(): array
    {
        /** @var array{state: string, instance: string, connected: bool} $estado */
        $estado = Cache::remember($this->clave(), self::SEGUNDOS, function (): array {
            // Si Evolution está caído, la bandeja tiene que seguir siendo usable: se lee lo que
            // hay guardado y se contesta el correo, aunque la línea no esté.
            try {
                return $this->connection->status();
            } catch (Throwable) {
                return ['state' => 'error', 'instance' => '—', 'connected' => false];
            }
        });

        return $estado + self::comoSeLee((string) $estado['state']);
    }

    /**
     * El estado, en algo que una persona pueda leer.
     *
     * Vive en PHP y no en el JavaScript de la pantalla porque si no habría DOS mapas: uno para el
     * primer pintado y otro para los refrescos, y en cuanto se tocara uno empezarían a decir cosas
     * distintas. Así la pantalla solo pinta lo que le llega, y el primer pintado sale ya con el
     * texto puesto en vez de con una etiqueta vacía hasta que arranca Alpine.
     *
     * @return array{label: string, tono: string, pulso: bool, pista: string, badge: string}
     */
    public static function comoSeLee(string $state): array
    {
        return match ($state) {
            'open' => [
                'label' => 'En línea', 'tono' => 'viva', 'pulso' => true, 'badge' => 'badge-green',
                'pista' => 'La línea está activa: puedes enviar y recibir mensajes.',
            ],
            'connecting' => [
                'label' => 'Conectando', 'tono' => 'espera', 'pulso' => true, 'badge' => 'badge-amber',
                'pista' => 'Termina el paso que quedó abierto para completar el vínculo.',
            ],
            'close' => [
                'label' => 'Sesión cerrada', 'tono' => 'espera', 'pulso' => false, 'badge' => 'badge-amber',
                'pista' => 'La línea existe pero la sesión se cerró. Vuelve a conectarla.',
            ],
            'missing' => [
                'label' => 'Sin vincular', 'tono' => 'quieta', 'pulso' => false, 'badge' => 'badge-gray',
                'pista' => 'Todavía no has conectado un número a esta empresa.',
            ],
            'log' => [
                'label' => 'Modo registro', 'tono' => 'quieta', 'pulso' => false, 'badge' => 'badge-gray',
                'pista' => 'WhatsApp no está configurado en este servidor: los envíos se guardan, pero no salen.',
            ],
            default => [
                'label' => 'Sin conexión', 'tono' => 'caida', 'pulso' => false, 'badge' => 'badge-red',
                'pista' => 'No se pudo contactar con el proveedor de WhatsApp.',
            ],
        };
    }

    /** Algo cambió de verdad —lo dijo Evolution—: que la próxima consulta no use lo viejo. */
    public function olvidar(): void
    {
        Cache::forget($this->clave());
    }

    private function clave(): string
    {
        return 'wa:linea:'.($this->currentCompany->id() ?? 0);
    }
}
