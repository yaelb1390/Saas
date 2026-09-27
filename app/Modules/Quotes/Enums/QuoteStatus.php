<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Enums;

/**
 * En qué punto está una cotización.
 *
 * El recorrido normal es borrador → enviada → aceptada → convertida. Los estados no son adorno:
 * deciden qué se puede hacer con ella. Una caducada no se cobra sin volver a mirarla, y una ya
 * convertida no se convierte otra vez.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Sent => 'Enviada',
            self::Accepted => 'Aceptada',
            self::Rejected => 'Rechazada',
            self::Expired => 'Caducada',
            self::Converted => 'Convertida en venta',
        };
    }

    /** El tono de la etiqueta en pantalla, con los mismos nombres que el resto del panel. */
    public function tono(): string
    {
        return match ($this) {
            self::Draft => 'badge-gray',
            self::Sent => 'badge-blue',
            self::Accepted => 'badge-amber',
            self::Converted => 'badge-green',
            self::Rejected, self::Expired => 'badge-red',
        };
    }

    /**
     * ¿Se puede convertir en venta?
     *
     * Una CADUCADA no: el precio que lleva dejó de estar vigente y cobrarlo sin mirarlo es
     * exactamente el error que la fecha de validez viene a evitar. Una RECHAZADA tampoco: el cliente
     * dijo que no, y cobrarla sería cobrar algo que nadie aceptó.
     *
     * Y una ya CONVERTIDA no, porque ya se cobró una vez.
     */
    public function sePuedeConvertir(): bool
    {
        return in_array($this, [self::Draft, self::Sent, self::Accepted], true);
    }

    /** ¿Todavía se puede editar? Una vez cobrada, el documento es del pasado. */
    public function sePuedeEditar(): bool
    {
        return in_array($this, [self::Draft, self::Sent], true);
    }

    /**
     * ¿Se puede eliminar? Solo una CONVERTIDA no: ya es una venta de verdad —con su cobro, su
     * descuento de existencias, tal vez su factura—, y borrar la cotización no debe desligar ni
     * esconder nada de eso. Las demás (rechazada, caducada, borrador…) se pueden quitar de en medio
     * sin que le falte nada a ningún otro registro.
     */
    public function sePuedeEliminar(): bool
    {
        return $this !== self::Converted;
    }
}
