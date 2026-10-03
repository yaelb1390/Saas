<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Tax;

use InvalidArgumentException;

/**
 * Calcula el ITBIS y los totales de un e-CF por línea, como lo define el Formato e-CF.
 *
 * Aparte de `Core\Support\TaxCalculator` a propósito: aquel calcula el documento entero a una sola
 * tasa (lo que usa la serie B en papel) y no sabe de ítems exentos ni de otras tasas. Este no lo
 * reemplaza; se usa para el e-CF.
 *
 * Reglas, todas de [FMT]:
 *   · monto del ítem = precio × cantidad − descuento + recargo (campo 39);
 *   · monto gravado de una tasa = suma de los ítems de esa tasa, y si los precios incluyen ITBIS
 *     se divide entre (1 + tasa) (campos 93–95, indicador monto gravado = 1);
 *   · ITBIS de una tasa = monto gravado × tasa (campos 101–103);
 *   · exento = suma de los ítems con indicador 4 (campo 96);
 *   · total = gravado + exento + ITBIS (campo 110).
 *
 * Los impuestos adicionales (selectivos, ad valorem) y las retenciones no están aquí todavía: se
 * añadirán con los tipos de e-CF que los usan, desde su especificación.
 */
final class TaxEngine
{
    private const ESCALA = 6;

    /**
     * @param  list<TaxLine>  $lines
     */
    public function calculate(array $lines, bool $pricesIncludeTax): TaxResult
    {
        $montos = [];
        $porIndicador = [1 => '0', 2 => '0', 3 => '0'];
        $exento = '0';
        $cobrado = '0';

        foreach ($lines as $linea) {
            $this->assertValida($linea);

            $monto = bcadd(
                bcsub(bcmul($linea->unitPrice, $linea->quantity, self::ESCALA), $linea->discount, self::ESCALA),
                $linea->surcharge,
                self::ESCALA,
            );

            if (bccomp($monto, '0', self::ESCALA) < 0) {
                throw new InvalidArgumentException('El descuento de una línea no puede ser mayor que su importe.');
            }

            $montos[] = $this->redondear($monto);

            if ($linea->indicator === BillingIndicator::NoFacturable) {
                continue;
            }

            $cobrado = bcadd($cobrado, $monto, self::ESCALA);

            if ($linea->indicator === BillingIndicator::Exento) {
                $exento = bcadd($exento, $monto, self::ESCALA);

                continue;
            }

            $porIndicador[$linea->indicator->value] = bcadd($porIndicador[$linea->indicator->value], $monto, self::ESCALA);
        }

        $gravado = [];
        $itbis = [];

        foreach ($porIndicador as $indicador => $suma) {
            $tasa = bcdiv((string) BillingIndicator::from($indicador)->rate(), '100', self::ESCALA);

            $base = $pricesIncludeTax
                ? bcdiv($suma, bcadd('1', $tasa, self::ESCALA), self::ESCALA)
                : $suma;

            $gravado[$indicador] = $this->redondear($base);
            // Sobre la base YA redondeada: es la cifra que el e-CF declara [FMT campo 101].
            $itbis[$indicador] = $this->redondear(bcmul($gravado[$indicador], $tasa, self::ESCALA));
        }

        $totalGravado = $this->sumar($gravado);
        $totalItbis = $this->sumar($itbis);
        $exentoRedondeado = $this->redondear($exento);
        $total = $this->sumar([$totalGravado, $exentoRedondeado, $totalItbis]);

        // Lo cobrado es la suma de montos tal cual; sin ITBIS incluido, el cliente paga además el ITBIS.
        $cobradoTotal = $pricesIncludeTax
            ? $this->redondear($cobrado)
            : $this->sumar([$this->redondear($cobrado), $totalItbis]);

        return new TaxResult(
            lineAmounts: $montos,
            taxedBase: $gravado,
            taxedTotal: $totalGravado,
            exempt: $exentoRedondeado,
            itbis: $itbis,
            itbisTotal: $totalItbis,
            total: $total,
            chargedTotal: $cobradoTotal,
            difference: bcsub($cobradoTotal, $total, 2),
            pricesIncludeTax: $pricesIncludeTax,
        );
    }

    private function assertValida(TaxLine $linea): void
    {
        foreach (['quantity' => $linea->quantity, 'unitPrice' => $linea->unitPrice, 'discount' => $linea->discount, 'surcharge' => $linea->surcharge] as $campo => $valor) {
            if (! is_numeric($valor) || bccomp($valor, '0', self::ESCALA) < 0) {
                throw new InvalidArgumentException("El valor «{$campo}» de una línea debe ser un número no negativo.");
            }
        }
    }

    /** @param  array<int|string, string>  $valores */
    private function sumar(array $valores): string
    {
        return array_reduce($valores, fn (string $acc, string $v): string => bcadd($acc, $v, 2), '0.00');
    }

    /**
     * Redondeo a los decimales del e-CF. Solo «half_up» está implementado; la regla oficial está
     * pendiente de confirmar (config ecf.pending_verification.rounding_rule).
     */
    private function redondear(string $valor): string
    {
        $decimales = (int) config('ecf.itbis.decimals', 2);
        $regla = (string) config('ecf.itbis.rounding', 'half_up');

        if ($regla !== 'half_up') {
            throw new InvalidArgumentException("Regla de redondeo no implementada: {$regla}.");
        }

        $mitad = '0.'.str_repeat('0', $decimales).'5';
        $ajustado = bccomp($valor, '0', self::ESCALA) >= 0
            ? bcadd($valor, $mitad, self::ESCALA)
            : bcsub($valor, $mitad, self::ESCALA);

        return bcadd($ajustado, '0', $decimales);
    }
}
