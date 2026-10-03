<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Tax;

/**
 * Indicador de facturación de un ítem [FMT, sección B, campo 4 <IndicadorFacturacion>].
 *
 * La tasa de cada indicador vive en config/ecf.php (`itbis.rates`), no aquí: si la DGII cambia una
 * tasa, se cambia la configuración.
 */
enum BillingIndicator: int
{
    case NoFacturable = 0;
    case Itbis1 = 1;
    case Itbis2 = 2;
    case Itbis3 = 3;
    case Exento = 4;

    public function label(): string
    {
        return match ($this) {
            self::NoFacturable => 'No facturable',
            self::Itbis1 => 'Gravado ITBIS '.$this->rate().' %',
            self::Itbis2 => 'Gravado ITBIS '.$this->rate().' %',
            self::Itbis3 => 'Gravado ITBIS '.$this->rate().' %',
            self::Exento => 'Exento',
        };
    }

    /** Tasa en porcentaje entero («18»), o null si el indicador no lleva ITBIS. */
    public function rate(): ?string
    {
        $tasa = config("ecf.itbis.rates.{$this->value}");

        return $tasa === null ? null : (string) $tasa;
    }

    public function isTaxed(): bool
    {
        return in_array($this, [self::Itbis1, self::Itbis2, self::Itbis3], true);
    }

    /** Los que un producto del catálogo puede tener (no facturable es cosa de la línea, no del producto). */
    public static function forProducts(): array
    {
        return [self::Itbis1, self::Itbis2, self::Itbis3, self::Exento];
    }
}
