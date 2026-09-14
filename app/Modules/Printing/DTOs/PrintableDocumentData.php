<?php

declare(strict_types=1);

namespace App\Modules\Printing\DTOs;

/**
 * El contrato genérico de «lo que hay que imprimir», independiente del módulo de origen.
 *
 * ES LA PIEZA QUE HACE AL CENTRO DE IMPRESIÓN REALMENTE GENÉRICO: `DocumentRenderer` solo conoce esta
 * forma —un título, unos metadatos, unas líneas, unos totales—, nunca una Venta o un Préstamo. Cuando
 * otro módulo se enchufe, lo único que escribe es un adaptador pequeño (como `SaleTicketAdapter`) que
 * convierte SU modelo a esta forma. El renderizador y las plantillas no cambian ni se enteran.
 */
final readonly class PrintableDocumentData
{
    /**
     * @param  array<int, array{label: string, value: string}>  $meta  fecha, cliente, forma de pago…
     * @param  array<int, array{qty: ?string, description: string, detail: ?string, amount: ?string}>  $lines
     * @param  array<int, array{label: string, value: string, emphasis: bool}>  $totals
     */
    public function __construct(
        public string $title,
        public ?string $reference = null,
        public array $meta = [],
        public array $lines = [],
        public array $totals = [],
        public ?string $note = null,
    ) {}
}
