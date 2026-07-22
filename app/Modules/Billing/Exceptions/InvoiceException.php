<?php

declare(strict_types=1);

namespace App\Modules\Billing\Exceptions;

use App\Modules\Billing\Enums\NcfType;
use DomainException;

final class InvoiceException extends DomainException
{
    public static function saleNotCompleted(int $saleId): self
    {
        return new self("La venta {$saleId} no está completada; no puede facturarse.");
    }

    public static function alreadyInvoiced(int $saleId): self
    {
        return new self("La venta {$saleId} ya tiene una factura emitida.");
    }

    /**
     * El Crédito Fiscal y el Gubernamental sustentan gasto del comprador: la DGII exige el RNC.
     */
    public static function taxIdRequired(NcfType $type): self
    {
        return new self("El comprobante «{$type->label()}» exige el RNC o cédula del cliente.");
    }

    public static function invalidTaxId(string $taxId): self
    {
        return new self("El RNC/cédula «{$taxId}» no es válido: el dígito verificador no coincide.");
    }

    public static function alreadyCancelled(string $ncf): self
    {
        return new self("El comprobante {$ncf} ya está anulado.");
    }
}
