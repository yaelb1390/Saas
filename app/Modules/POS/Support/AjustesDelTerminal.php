<?php

declare(strict_types=1);

namespace App\Modules\POS\Support;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\CurrentCompany;

/**
 * Lo que el negocio tiene ENCENDIDO en su terminal, aplicado del lado del servidor.
 *
 * POR QUÉ EXISTE. Los interruptores de `PosProfile` —descuentos, propina, nº de serie, cantidad
 * decimal…— solo escondían controles en la pantalla. Ninguna de las tres pantallas de cobro los
 * comprobaba al recibir la venta, así que apagarlos no impedía nada: bastaba una pestaña vieja
 * abierta de antes de apagarlo, una venta sin conexión sincronizada después, o una petición hecha a
 * mano, para que el descuento o la propina entraran igual. En un negocio que decidió no dar
 * descuentos, eso es dinero que se va sin que nadie lo haya autorizado.
 *
 * Es la misma doctrina que ya sigue el precio en `CartResolver`: EL NAVEGADOR PROPONE, EL SERVIDOR
 * DECIDE. Aquí solo se traslada al resto de los campos.
 *
 * SE IGNORA, NO SE RECHAZA. Una opción apagada no es un ataque ni un error del cajero: casi siempre
 * es una pantalla abierta desde antes del cambio. Devolverle un error le haría perder el ticket
 * entero por un campo que su negocio ya no usa; vaciar ese campo cobra bien y no interrumpe.
 */
final readonly class AjustesDelTerminal
{
    /** @param array<string, bool> $opciones */
    private function __construct(private array $opciones) {}

    public static function de(?Company $empresa): self
    {
        return new self($empresa === null
            ? PosProfile::defaults(PosProfile::DEFAULT)
            : PosProfile::for($empresa)['options']);
    }

    /** Los ajustes de la empresa en la que se está trabajando ahora. */
    public static function activos(): self
    {
        return self::de(app(CurrentCompany::class)->model());
    }

    public function permite(string $opcion): bool
    {
        return (bool) ($this->opciones[$opcion] ?? false);
    }

    /**
     * Un importe que depende de un interruptor: tal cual si está encendido, cero si no.
     *
     * Nunca negativo, encendido o apagado. Un «descuento» de −100 sería un recargo colado por la
     * puerta de atrás.
     */
    public function importe(string $opcion, mixed $valor): string
    {
        if (! $this->permite($opcion)) {
            return '0';
        }

        return (string) max(0, (float) $valor);
    }

    /** Un texto accesorio —nota, nº de serie— que solo se guarda si su interruptor está encendido. */
    public function texto(string $opcion, mixed $valor): ?string
    {
        return $this->permite($opcion) && filled($valor) ? (string) $valor : null;
    }

    /** Un identificador que solo se guarda si su interruptor está encendido. */
    public function identificador(string $opcion, ?int $valor): ?int
    {
        return $this->permite($opcion) ? $valor : null;
    }

    /**
     * La cantidad de una línea.
     *
     * Sin «cantidad decimal» se redondea al entero más cercano, porque el negocio no vende medias
     * unidades: dejar pasar 0,4 de un tornillo descuadraría la existencia con un número que el
     * inventario no puede representar. Nunca baja de 1 —una línea de cero no es una venta— y con la
     * opción encendida se respeta el decimal tal cual.
     */
    public function cantidad(mixed $valor): string
    {
        $cantidad = (float) $valor;

        if ($this->permite('decimal_qty')) {
            return (string) max(0.001, $cantidad);
        }

        return (string) max(1, (int) round($cantidad));
    }
}
