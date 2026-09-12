<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\ProductUnit;

/**
 * La ficha de una unidad buscada por su serie: qué es, dónde está y qué le ha pasado.
 *
 * ES LA RAZÓN DE SER DE TODO EL MÓDULO. Serializar no sirve de nada si, cuando un cliente vuelve seis
 * meses después con un teléfono roto, no se puede saber si lo compró aquí, cuándo, y qué era. Esta
 * clase contesta esa pregunta a partir de lo único que trae el cliente: el número de serie.
 *
 * Se arma de datos que YA existen —la unidad, su venta, el cliente de esa venta—; no guarda nada
 * nuevo. La garantía como tal (cuántos meses, si sigue vigente) es una capa que iría encima de esto,
 * pero el cimiento es saber de quién es cada aparato, y eso es lo que hay aquí.
 */
final readonly class UnitHistory
{
    private function __construct(
        public ProductUnit $unidad,
    ) {}

    /**
     * Busca la unidad por su serie dentro de la empresa activa.
     *
     * INCLUYE LAS VENDIDAS, a propósito: es justo la que más se va a buscar, porque el cliente vuelve
     * DESPUÉS de comprar. Buscar solo entre las disponibles dejaría fuera el caso para el que existe
     * esta pantalla.
     */
    public static function porSerie(string $serial): ?self
    {
        $serial = trim($serial);

        if ($serial === '') {
            return null;
        }

        $unidad = ProductUnit::query()
            ->with(['product', 'warehouse', 'sale.customer'])
            ->where('serial', $serial)
            ->first();

        return $unidad === null ? null : new self($unidad);
    }

    /**
     * La historia en la forma que pinta la vista: qué pasó y cuándo, en orden.
     *
     * @return array<int, array{cuando: ?string, hecho: string, detalle: ?string}>
     */
    public function linea(): array
    {
        $eventos = [];

        $eventos[] = [
            'cuando' => $this->unidad->received_at?->format('d/m/Y'),
            'hecho' => 'Entró al inventario',
            'detalle' => $this->unidad->warehouse?->name,
        ];

        if ($this->unidad->status === ProductUnit::VENDIDA && $this->unidad->sale !== null) {
            $eventos[] = [
                'cuando' => $this->unidad->sold_at?->format('d/m/Y'),
                'hecho' => 'Se vendió',
                // A quién: el cliente de la ficha, o el nombre escrito en el recibo, o consumidor final.
                'detalle' => $this->aQuien(),
            ];
        }

        if ($this->unidad->status === ProductUnit::DEVUELTA) {
            $eventos[] = ['cuando' => $this->unidad->updated_at?->format('d/m/Y'), 'hecho' => 'Devuelta', 'detalle' => null];
        }

        return $eventos;
    }

    /** A quién se le vendió, en palabras. */
    public function aQuien(): string
    {
        $venta = $this->unidad->sale;

        return $venta?->customer?->name
            ?? $venta?->customer_name
            ?? 'Consumidor final';
    }

    public function estaVendida(): bool
    {
        return $this->unidad->status === ProductUnit::VENDIDA;
    }
}
