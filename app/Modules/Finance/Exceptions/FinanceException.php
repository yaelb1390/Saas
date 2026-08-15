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
}
