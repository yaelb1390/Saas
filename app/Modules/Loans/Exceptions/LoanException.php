<?php

declare(strict_types=1);

namespace App\Modules\Loans\Exceptions;

use DomainException;

/**
 * Errores de negocio del módulo de préstamos. Los controladores los capturan y los convierten en
 * un mensaje `panel_error` para el usuario, sin abortar con un 500.
 */
final class LoanException extends DomainException
{
    public static function customerNotInCompany(int $customerId, int $companyId): self
    {
        return new self("El cliente {$customerId} no pertenece a la empresa {$companyId}.");
    }

    public static function invalidAmount(): self
    {
        return new self('El monto debe ser mayor que cero.');
    }

    public static function notActive(): self
    {
        return new self('El préstamo no está vigente: no admite cobros.');
    }

    public static function paymentExceedsBalance(string $balance): self
    {
        return new self("El abono supera el saldo pendiente (RD$ {$balance}).");
    }

    public static function alreadyCancelled(): self
    {
        return new self('El préstamo ya está anulado.');
    }

    public static function hasPayments(): self
    {
        return new self('No se puede anular un préstamo que ya tiene cobros registrados.');
    }

    // ------------------------------------------------------------------ Solicitudes de préstamo

    public static function solicitudNoEditable(string $estado): self
    {
        return new self("Una solicitud {$estado} ya no se puede modificar: sus términos quedaron fijados al decidirla.");
    }

    public static function solicitudNoDecidible(string $estado): self
    {
        return new self("Esta solicitud está {$estado}, así que ya no se puede aprobar ni rechazar.");
    }

    public static function solicitudNoDesembolsable(string $estado): self
    {
        return new self("Solo se desembolsa una solicitud aprobada; esta está {$estado}.");
    }

    /**
     * El fallo que costaría dinero de verdad: dos desembolsos de la misma solicitud son dos
     * préstamos y dos salidas de caja por una sola operación.
     */
    public static function solicitudYaDesembolsada(string $codigoPrestamo): self
    {
        return new self("Esta solicitud ya se desembolsó en el préstamo {$codigoPrestamo}.");
    }

    public static function solicitudNoReabrible(string $estado): self
    {
        return new self("Una solicitud {$estado} no se puede reabrir. Para deshacer un desembolso hay que anular el préstamo.");
    }
}
