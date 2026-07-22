<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Único punto del sistema donde se calcula el ITBIS.
 *
 * Vive en Core porque es un concepto compartido: Ventas lo necesita para registrar los importes
 * y Facturación para desglosarlos en el comprobante. Si viviera en Billing, Sales dependería de
 * Billing y se invertiría la dirección de las dependencias.
 *
 * Modelo configurado: el precio del producto YA incluye el ITBIS (práctica habitual del comercio
 * dominicano). El impuesto se extrae hacia atrás, de modo que el cliente paga exactamente el
 * precio que vio: base + ITBIS = precio.
 */
final readonly class TaxCalculator
{
    private const SCALE = 2;

    /** Escala intermedia: se calcula con más precisión y solo se redondea al final. */
    private const WORK_SCALE = 6;

    public function __construct(
        private string $rate = '18',
        private bool $pricesIncludeTax = true,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rate: (string) config('billing.itbis_rate', '18'),
            pricesIncludeTax: (bool) config('billing.prices_include_tax', true),
        );
    }

    /**
     * Desglosa un importe de venta (lo que se cobra al cliente) en base imponible e ITBIS.
     *
     * @return array{subtotal: string, tax: string, total: string}
     */
    public function breakdown(string $amount): array
    {
        if ($this->rate === '0') {
            return ['subtotal' => $this->round($amount), 'tax' => '0.00', 'total' => $this->round($amount)];
        }

        if ($this->pricesIncludeTax) {
            // El importe ya lleva el impuesto dentro: base = importe / (1 + tasa).
            $base = $this->round(bcdiv($amount, $this->multiplier(), self::WORK_SCALE));
            $total = $this->round($amount);

            // El ITBIS se obtiene por diferencia, no por multiplicación: así base + ITBIS
            // cuadra exactamente con el total y no aparecen descuadres de un centavo.
            return ['subtotal' => $base, 'tax' => bcsub($total, $base, self::SCALE), 'total' => $total];
        }

        // El importe es la base y el impuesto se suma encima.
        $base = $this->round($amount);
        $total = $this->round(bcmul($amount, $this->multiplier(), self::WORK_SCALE));

        return ['subtotal' => $base, 'tax' => bcsub($total, $base, self::SCALE), 'total' => $total];
    }

    /** Factor 1 + tasa/100 (p. ej. 1.18). */
    private function multiplier(): string
    {
        return bcadd('1', bcdiv($this->rate, '100', self::WORK_SCALE), self::WORK_SCALE);
    }

    /**
     * Redondeo comercial (medio hacia arriba). bcdiv/bcmul truncan, y truncar impuestos
     * arrastra centavos que la DGII detecta al cuadrar el 607.
     */
    private function round(string $value): string
    {
        $offset = bccomp($value, '0', self::WORK_SCALE) < 0 ? '-0.005' : '0.005';

        return bcadd($value, $offset, self::SCALE);
    }
}
