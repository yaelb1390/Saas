<?php

declare(strict_types=1);

namespace App\Modules\Loans\Enums;

/**
 * Estados por los que pasa una solicitud de préstamo.
 *
 * El recorrido normal es: recibida → (en evaluación) → aprobada → desembolsada. Rechazada y
 * desistida son las dos salidas.
 *
 * Se puede ir de «recibida» directo a «aprobada» sin pasar por «en evaluación»: en una agencia
 * pequeña, donde quien recibe la solicitud a veces es quien la aprueba, obligar a marcar un paso
 * intermedio sería un clic de peaje que la gente aprendería a saltarse mal.
 *
 * Que «aprobada» y «desembolsada» sean estados DISTINTOS es la razón de ser de todo esto: aprobar es
 * una decisión, desembolsar es sacar el dinero de la caja. Hoy van pegados —el préstamo nace ya
 * desembolsado—, y por eso no hay forma de revisar una aprobación antes de entregar el efectivo.
 */
enum LoanApplicationStatus: string
{
    case Received = 'received';       // entró, nadie la ha mirado todavía
    case UnderReview = 'under_review'; // alguien está tomando datos y evaluando
    case Approved = 'approved';       // decidida a favor, el dinero NO ha salido
    case Rejected = 'rejected';       // decidida en contra
    case Disbursed = 'disbursed';     // el dinero salió y existe el préstamo
    case Cancelled = 'cancelled';     // el cliente desistió

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recibida',
            self::UnderReview => 'En evaluación',
            self::Approved => 'Aprobada',
            self::Rejected => 'Rechazada',
            self::Disbursed => 'Desembolsada',
            self::Cancelled => 'Desistida',
        };
    }

    /**
     * Clase de la etiqueta de color en el panel. Se usan las que ya existen en `app.css`; inventar
     * una aquí saldría gris, porque Tailwind solo genera las clases que encuentra escritas.
     *
     * El VERDE se reserva a «desembolsada», no a «aprobada»: mientras el dinero no haya salido la
     * operación no está cerrada, y pintarla de verde antes invita a darla por terminada, que es
     * justo la confusión que este módulo viene a deshacer.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Received => 'badge-gray',
            self::UnderReview => 'badge-amber',
            self::Approved => 'badge-blue',
            self::Rejected => 'badge-red',
            self::Disbursed => 'badge-green',
            self::Cancelled => 'badge-gray',
        };
    }

    /**
     * Estados desde los que todavía se pueden tocar los datos de la solicitud y su evaluación.
     *
     * Una vez decidida, los términos quedan congelados: si se pudiera editar el capital pedido
     * después de aprobar, el expediente dejaría de decir qué se aprobó en realidad.
     */
    public function admiteEdicion(): bool
    {
        return $this === self::Received || $this === self::UnderReview;
    }

    /**
     * Estados desde los que se puede decidir (aprobar o rechazar).
     */
    public function admiteDecision(): bool
    {
        return $this === self::Received || $this === self::UnderReview;
    }

    /**
     * Una decisión se puede revisar mientras el dinero no haya salido.
     *
     * «Desembolsada» no vuelve atrás por diseño: deshacerla exigiría reversar el egreso de caja y el
     * préstamo ya creado, que es justo lo que hace `LoanService::cancel()` con sus propias reglas.
     */
    public function admiteReapertura(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }

    /**
     * @return array<int, self>
     */
    public static function abiertas(): array
    {
        return [self::Received, self::UnderReview, self::Approved];
    }
}
