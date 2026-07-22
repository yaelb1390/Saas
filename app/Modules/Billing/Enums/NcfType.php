<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * Tipos de Comprobante Fiscal (NCF) de la DGII (RD). El valor es el prefijo del NCF.
 * (Modelo simplificado con los tipos más comunes; ampliable a e-CF E31/E32, etc.)
 */
enum NcfType: string
{
    case CreditoFiscal = 'B01';   // Factura de Crédito Fiscal
    case Consumo = 'B02';         // Factura de Consumo
    case NotaDebito = 'B03';      // Nota de Débito
    case NotaCredito = 'B04';     // Nota de Crédito
    case Gubernamental = 'B15';   // Comprobante Gubernamental

    public function label(): string
    {
        return match ($this) {
            self::CreditoFiscal => 'Crédito Fiscal',
            self::Consumo => 'Consumo',
            self::NotaDebito => 'Nota de Débito',
            self::NotaCredito => 'Nota de Crédito',
            self::Gubernamental => 'Gubernamental',
        };
    }

    /**
     * Comprobantes que sustentan crédito fiscal o gasto del comprador: la DGII exige identificar
     * al cliente con RNC o cédula. En Consumo (B02) es opcional.
     */
    public function requiresTaxId(): bool
    {
        return match ($this) {
            self::CreditoFiscal, self::Gubernamental, self::NotaDebito, self::NotaCredito => true,
            self::Consumo => false,
        };
    }
}
