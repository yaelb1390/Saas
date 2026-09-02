<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

/**
 * Forma de pago de una venta.
 *
 * Los valores NO son arbitrarios: `DgiiReportService::paymentCode()` los traduce a los códigos de la
 * columna 23 del formato 606 (cash → 01, transfer → 02, card → 03). Cambiar una cadena aquí rompe la
 * declaración fiscal, así que se mantienen tal cual.
 *
 * Se incluyen las cinco formas que la API ya aceptaba (`StoreSaleRequest`), no solo las del
 * mostrador: reducir el conjunto aquí habría roto ese endpoint. El punto de venta ofrece únicamente
 * las tres que tienen sentido en un cobro al contado (ver `counterOptions()`).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';
    case Check = 'check';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Card => 'Tarjeta',
            self::Transfer => 'Transferencia',
            self::Check => 'Cheque',
            self::Credit => 'Crédito',
        };
    }

    /**
     * El color con el que se reconoce esta forma de pago.
     *
     * Vive aquí y no en la plantilla por lo mismo que `label()`: los dos terminales —mostrador y
     * venta rápida— tienen que pintarla igual, y una forma de pago nueva tiene que decidir su
     * color en un solo sitio.
     *
     * El verde del efectivo es el único con significado propio —es el dinero que entra en el
     * cajón, lo mismo que decide `entersCashDrawer()`—; los otros solo tienen que distinguirse
     * entre sí de un vistazo.
     */
    public function tono(): string
    {
        return match ($this) {
            self::Cash => 'verde',
            self::Card => 'azul',
            self::Transfer => 'cian',
            self::Check => 'ambar',
            self::Credit => 'gris',
        };
    }

    /**
     * Las que se ofrecen en el mostrador. Cheque y crédito quedan fuera: no son cobros al contado y
     * el punto de venta exige que el pago cubra el total.
     *
     * @return array<int, self>
     */
    public static function counterOptions(): array
    {
        return [self::Cash, self::Card, self::Transfer];
    }

    /**
     * ¿Este cobro mete dinero físico en el cajón?
     *
     * Es la pregunta que decide si la venta engorda el arqueo de caja. Cobrar con tarjeta o por
     * transferencia no deja efectivo en el cajón: contarlo ahí haría que el cierre saliera siempre
     * con un faltante igual a lo cobrado por esas vías.
     */
    public function entersCashDrawer(): bool
    {
        return $this === self::Cash;
    }
}
