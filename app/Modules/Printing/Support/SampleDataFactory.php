<?php

declare(strict_types=1);

namespace App\Modules\Printing\Support;

use App\Modules\Printing\DTOs\PrintableDocumentData;

/**
 * Datos de muestra para probar una plantilla ANTES de que el módulo real esté conectado.
 *
 * Es lo que hace posible «Prueba de impresión» y la vista previa del editor para los 28 tipos de
 * documento que todavía no tienen un adaptador como `SaleTicketAdapter`: sin esto, configurar la
 * plantilla de un reporte financiero tendría que esperar a que Reportes se conecte al Centro de
 * Impresión, y ese no es el orden en que un dueño de negocio quiere trabajar.
 */
final class SampleDataFactory
{
    public static function paraTipo(string $documentType): PrintableDocumentData
    {
        $categoria = DocumentType::category($documentType) ?? DocumentType::CATEGORY_DOCUMENT;

        return match ($categoria) {
            DocumentType::CATEGORY_TICKET => self::ticket($documentType),
            DocumentType::CATEGORY_INVOICE => self::factura($documentType),
            DocumentType::CATEGORY_REPORT => self::reporte($documentType),
            default => self::documento($documentType),
        };
    }

    private static function ticket(string $tipo): PrintableDocumentData
    {
        return new PrintableDocumentData(
            title: DocumentType::label($tipo),
            reference: 'PRUEBA-0001',
            meta: [
                ['label' => 'Fecha', 'value' => now()->format('d/m/Y H:i')],
                ['label' => 'Cliente', 'value' => 'Cliente de prueba'],
            ],
            lines: [
                ['qty' => '2', 'description' => 'Producto de muestra', 'detail' => '250.00 c/u', 'amount' => '500.00'],
                ['qty' => '1', 'description' => 'Otro artículo', 'detail' => null, 'amount' => '150.00'],
            ],
            totals: [
                ['label' => 'Subtotal', 'value' => '650.00', 'emphasis' => false],
                ['label' => 'ITBIS', 'value' => '117.00', 'emphasis' => false],
                ['label' => 'TOTAL', 'value' => '767.00', 'emphasis' => true],
            ],
            note: 'Esto es una prueba de impresión.',
        );
    }

    private static function factura(string $tipo): PrintableDocumentData
    {
        $d = self::ticket($tipo);

        return new PrintableDocumentData(
            title: $d->title,
            reference: 'B0100000001',
            meta: [...$d->meta, ['label' => 'NCF', 'value' => 'B0100000001']],
            lines: $d->lines,
            totals: $d->totals,
            note: $d->note,
        );
    }

    private static function reporte(string $tipo): PrintableDocumentData
    {
        return new PrintableDocumentData(
            title: DocumentType::label($tipo),
            reference: now()->format('Y-m-d'),
            meta: [
                ['label' => 'Periodo', 'value' => now()->startOfMonth()->format('d/m').' – '.now()->format('d/m/Y')],
                ['label' => 'Generado por', 'value' => 'Prueba de impresión'],
            ],
            lines: [
                ['qty' => null, 'description' => 'Concepto de muestra A', 'detail' => null, 'amount' => '12,500.00'],
                ['qty' => null, 'description' => 'Concepto de muestra B', 'detail' => null, 'amount' => '8,300.00'],
            ],
            totals: [
                ['label' => 'Total', 'value' => '20,800.00', 'emphasis' => true],
            ],
            note: 'Esto es una prueba de impresión.',
        );
    }

    private static function documento(string $tipo): PrintableDocumentData
    {
        return new PrintableDocumentData(
            title: DocumentType::label($tipo),
            reference: 'DOC-0001',
            meta: [
                ['label' => 'Fecha', 'value' => now()->format('d/m/Y')],
                ['label' => 'Para', 'value' => 'Cliente de prueba'],
            ],
            lines: [],
            totals: [],
            note: 'Esto es una prueba de impresión.',
        );
    }
}
