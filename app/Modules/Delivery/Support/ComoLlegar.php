<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Support;

use App\Modules\Delivery\Models\Delivery;

/**
 * A dónde tiene que ir el repartidor, en forma de enlace que abre su navegador de siempre.
 *
 * POR QUÉ ESTO EXISTE. Una dirección dominicana de verdad es «Juana #6, callejón blanco». Ningún
 * buscador la resuelve, así que el repartidor acaba llamando por teléfono para que le expliquen cómo
 * llegar — que es justo lo que la pantalla debería ahorrarle.
 *
 * La solución no es adivinar la dirección: es APRENDERLA. La primera vez se navega por el texto, que
 * es lo mismo que podría hacer a mano; cuando el repartidor llega y pulsa «Guardar esta ubicación»,
 * queda el punto exacto, y a partir de ahí este mismo enlace lleva a la puerta.
 *
 * NO SE EMBEBE NINGÚN MAPA. Se abre Waze o Google Maps, que el repartidor ya tiene instalados y le
 * hablan mientras conduce. Eso evita una librería de mapas, teselas que descargar con datos móviles y
 * una clave de API con tarjeta detrás; y un mapa mudo dentro de la pantalla, sin voz y sin tráfico,
 * sería peor que la aplicación que ya usa.
 *
 * El orden de dónde sale el punto —la entrega primero, la ficha del cliente después— vive AQUÍ y en
 * ningún otro sitio: si cada pantalla lo decidiera por su cuenta, dos botones de la misma tarjeta
 * podrían llevar a sitios distintos.
 */
final readonly class ComoLlegar
{
    public function __construct(
        private ?string $latitud,
        private ?string $longitud,
        private string $direccion,
    ) {}

    /**
     * El destino de una entrega.
     *
     * Primero el punto de la propia entrega —dónde se entregó de verdad—, y si no lo tiene, el de la
     * ficha del cliente, que es dónde vive. Una venta de mostrador sin cliente vinculado se queda
     * sin punto, y entonces manda la dirección escrita.
     *
     * Leer una columna que todavía no existe devuelve null y no rompe: por eso aquí no hace falta
     * preguntar por la migración, solo al escribir.
     */
    public static function para(Delivery $delivery): self
    {
        $cliente = $delivery->customer;

        return new self(
            latitud: $delivery->latitude ?? $cliente?->latitude,
            longitud: $delivery->longitude ?? $cliente?->longitude,
            direccion: (string) $delivery->address,
        );
    }

    /** Si hay punto exacto, o hay que conformarse con el texto. */
    public function tienePunto(): bool
    {
        return $this->latitud !== null && $this->longitud !== null;
    }

    /**
     * Waze. `navigate=yes` arranca la ruta sola en vez de dejar el sitio en pantalla esperando otro
     * toque: con el móvil en el manillar, ese toque de menos importa.
     */
    public function waze(): string
    {
        return $this->tienePunto()
            ? 'https://waze.com/ul?ll='.$this->punto().'&navigate=yes'
            : 'https://waze.com/ul?q='.rawurlencode($this->direccion).'&navigate=yes';
    }

    /** Google Maps, en modo indicaciones desde donde esté. */
    public function googleMaps(): string
    {
        return 'https://www.google.com/maps/dir/?api=1&destination='.$this->destino();
    }

    /**
     * Coordenadas normalizadas a siete decimales.
     *
     * Se pasan como cadena y no como número: en coma flotante, «18.4861» puede salir como
     * «18.486099999999997» y ensuciar el enlace sin ganar nada. Siete decimales son ~1 cm, de sobra
     * para una puerta.
     */
    private function punto(): string
    {
        return number_format((float) $this->latitud, 7, '.', '')
            .','.number_format((float) $this->longitud, 7, '.', '');
    }

    /** El punto si lo hay; si no, la dirección tal cual la escribieron. */
    private function destino(): string
    {
        return $this->tienePunto() ? $this->punto() : rawurlencode($this->direccion);
    }
}
