<?php

declare(strict_types=1);

namespace App\Modules\Finance\Exceptions;

use DomainException;

/**
 * Errores de negocio de Finanzas. Los controladores los capturan y los convierten en un mensaje
 * `panel_error` para el usuario, sin abortar con un 500.
 */
final class FinanceException extends DomainException
{
    public static function invalidAmount(): self
    {
        return new self('El monto del gasto debe ser mayor que cero.');
    }

    public static function accountNotInCompany(): self
    {
        return new self('La cuenta seleccionada no pertenece a esta empresa.');
    }

    public static function categoryNotInCompany(): self
    {
        return new self('El concepto de gasto seleccionado no pertenece a esta empresa.');
    }

    public static function accountInactive(string $nombre): self
    {
        return new self("La cuenta «{$nombre}» está desactivada: no se puede pagar desde ella.");
    }

    /**
     * El arqueo de un turno cerrado ya se contó y se firmó. Tocarlo después dejaría el cierre
     * diciendo una cifra distinta de la que se contó aquel día.
     */
    public static function cashSessionClosed(): self
    {
        return new self('Este gasto salió de un turno de caja que ya está cerrado, así que no se puede anular sin descuadrar aquel arqueo.');
    }

    public static function categoryInUse(string $nombre, int $cuantos): self
    {
        return new self("«{$nombre}» tiene {$cuantos} gasto(s) registrados. Desactívalo en vez de borrarlo para no perder el histórico.");
    }

    // ------------------------------------------------------------- Cuentas por cobrar/pagar

    public static function invalidPaymentAmount(): self
    {
        return new self('El monto del abono debe ser mayor que cero.');
    }

    public static function alreadySettled(): self
    {
        return new self('Esta cuenta ya está saldada: no admite más abonos.');
    }

    public static function paymentExceedsBalance(string $balance): self
    {
        return new self("El abono no puede superar el saldo pendiente ({$balance}).");
    }

    public static function customerNotInCompany(): self
    {
        return new self('El cliente seleccionado no pertenece a esta empresa.');
    }

    public static function supplierNotInCompany(): self
    {
        return new self('El proveedor seleccionado no pertenece a esta empresa.');
    }

    public static function saleAlreadyHasReceivable(string $code): self
    {
        return new self("Esta venta ya tiene una cuenta por cobrar ({$code}).");
    }

    public static function purchaseOrderAlreadyHasPayable(string $code): self
    {
        return new self("Esta orden de compra ya tiene una cuenta por pagar ({$code}).");
    }

    /**
     * Igual que `LoanException::hasPayments()`: una cuenta con abonos ya es historial de dinero
     * que entró o salió de verdad. Borrarla lo haría desaparecer sin dejar rastro.
     */
    public static function hasPayments(): self
    {
        return new self('Esta cuenta ya tiene abonos registrados: no se puede eliminar sin perder ese historial.');
    }

    public static function cannotEditTotalWithPayments(): self
    {
        return new self('Ya se registraron abonos sobre esta cuenta: el monto original no se puede cambiar.');
    }
}
