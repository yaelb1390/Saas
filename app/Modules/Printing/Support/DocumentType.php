<?php

declare(strict_types=1);

namespace App\Modules\Printing\Support;

/**
 * El catálogo de documentos imprimibles de BMIA: qué se puede imprimir, en qué categoría cae y con
 * qué tamaño de papel sale por omisión si nadie configuró una plantilla propia.
 *
 * ES UN CATÁLOGO, NO UNA INTEGRACIÓN. Registrar aquí `sale_ticket` no hace que el módulo de Ventas
 * use el Centro de Impresión —eso lo hace cada módulo llamando a `<x-print-button>` con esta clave—.
 * Lo que este catálogo garantiza es que la plantilla, el tamaño de papel y el historial hablan todos
 * el mismo lenguaje de claves, esté o no ya conectado el módulo real.
 *
 * La referencia ya conectada en esta etapa es `sale_ticket` (el recibo de venta). El resto queda
 * dado de alta y listo para que cada módulo lo enchufe cuando le toque.
 */
final class DocumentType
{
    public const CATEGORY_TICKET = 'ticket';

    public const CATEGORY_INVOICE = 'invoice';

    public const CATEGORY_REPORT = 'report';

    public const CATEGORY_DOCUMENT = 'document';

    /**
     * @var array<string, array{label: string, category: string, default_paper_size: string}>
     */
    private const TYPES = [
        // ---- A. Tickets ----
        'sale_ticket' => ['label' => 'Ticket de venta', 'category' => self::CATEGORY_TICKET, 'default_paper_size' => '80mm'],
        'loan_ticket' => ['label' => 'Ticket de préstamo', 'category' => self::CATEGORY_TICKET, 'default_paper_size' => '80mm'],
        'payment_ticket' => ['label' => 'Ticket de pago', 'category' => self::CATEGORY_TICKET, 'default_paper_size' => '80mm'],
        'deposit_ticket' => ['label' => 'Ticket de abono', 'category' => self::CATEGORY_TICKET, 'default_paper_size' => '80mm'],
        'return_ticket' => ['label' => 'Ticket de devolución', 'category' => self::CATEGORY_TICKET, 'default_paper_size' => '80mm'],
        'delivery_ticket' => ['label' => 'Ticket de entrega', 'category' => self::CATEGORY_TICKET, 'default_paper_size' => '80mm'],

        // ---- B. Facturas ----
        'invoice_letter' => ['label' => 'Factura tamaño carta', 'category' => self::CATEGORY_INVOICE, 'default_paper_size' => 'letter'],
        'invoice_half_letter' => ['label' => 'Factura media carta', 'category' => self::CATEGORY_INVOICE, 'default_paper_size' => 'half_letter'],
        'invoice_a4' => ['label' => 'Factura A4', 'category' => self::CATEGORY_INVOICE, 'default_paper_size' => 'a4'],
        'invoice_thermal' => ['label' => 'Factura térmica', 'category' => self::CATEGORY_INVOICE, 'default_paper_size' => '80mm'],
        'invoice_with_logo' => ['label' => 'Factura con logo', 'category' => self::CATEGORY_INVOICE, 'default_paper_size' => 'letter'],
        'invoice_without_logo' => ['label' => 'Factura sin logo', 'category' => self::CATEGORY_INVOICE, 'default_paper_size' => 'letter'],

        // ---- C. Reportes ----
        'daily_report' => ['label' => 'Reporte diario', 'category' => self::CATEGORY_REPORT, 'default_paper_size' => 'letter'],
        'monthly_report' => ['label' => 'Reporte mensual', 'category' => self::CATEGORY_REPORT, 'default_paper_size' => 'letter'],
        'sales_report' => ['label' => 'Reporte de ventas', 'category' => self::CATEGORY_REPORT, 'default_paper_size' => 'letter'],
        'loans_report' => ['label' => 'Reporte de préstamos', 'category' => self::CATEGORY_REPORT, 'default_paper_size' => 'letter'],
        'payments_report' => ['label' => 'Reporte de pagos', 'category' => self::CATEGORY_REPORT, 'default_paper_size' => 'letter'],
        'customers_report' => ['label' => 'Reporte de clientes', 'category' => self::CATEGORY_REPORT, 'default_paper_size' => 'letter'],
        'inventory_report' => ['label' => 'Reporte de inventario', 'category' => self::CATEGORY_REPORT, 'default_paper_size' => 'letter'],
        'expenses_report' => ['label' => 'Reporte de gastos', 'category' => self::CATEGORY_REPORT, 'default_paper_size' => 'letter'],
        'financial_report' => ['label' => 'Reporte financiero', 'category' => self::CATEGORY_REPORT, 'default_paper_size' => 'letter'],

        // ---- D. Documentos ----
        // No está en el spec como «documento del negocio» —es una utilidad del propio Centro de
        // Impresión, la del onboarding y el botón «Prueba de impresión» de cada impresora—, pero
        // vive en el mismo catálogo para no bifurcar la validación ni el renderizador por un caso
        // especial.
        'test_page' => ['label' => 'Página de prueba', 'category' => self::CATEGORY_DOCUMENT, 'default_paper_size' => '80mm'],
        'receipt' => ['label' => 'Recibo', 'category' => self::CATEGORY_DOCUMENT, 'default_paper_size' => '80mm'],
        'quote' => ['label' => 'Cotización', 'category' => self::CATEGORY_DOCUMENT, 'default_paper_size' => 'letter'],
        'purchase_order' => ['label' => 'Orden de compra', 'category' => self::CATEGORY_DOCUMENT, 'default_paper_size' => 'letter'],
        'service_order' => ['label' => 'Orden de servicio', 'category' => self::CATEGORY_DOCUMENT, 'default_paper_size' => 'letter'],
        'payment_voucher' => ['label' => 'Comprobante de pago', 'category' => self::CATEGORY_DOCUMENT, 'default_paper_size' => '80mm'],
        'account_statement' => ['label' => 'Estado de cuenta', 'category' => self::CATEGORY_DOCUMENT, 'default_paper_size' => 'letter'],
        'contract' => ['label' => 'Contrato', 'category' => self::CATEGORY_DOCUMENT, 'default_paper_size' => 'letter'],
        'labels' => ['label' => 'Etiquetas', 'category' => self::CATEGORY_DOCUMENT, 'default_paper_size' => '80x50'],
    ];

    /**
     * @return array<string, string> categoría => etiqueta, en el orden del formulario del spec.
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_TICKET => 'Tickets',
            self::CATEGORY_INVOICE => 'Facturas',
            self::CATEGORY_REPORT => 'Reportes',
            self::CATEGORY_DOCUMENT => 'Documentos',
        ];
    }

    /**
     * @return array<string, string> clave => etiqueta, todas las claves.
     */
    public static function options(): array
    {
        return array_map(static fn (array $t): string => $t['label'], self::TYPES);
    }

    /**
     * Las claves agrupadas por categoría, en el orden declarado — lo que pinta el selector del
     * editor de plantillas, agrupado tal como lo pidió el spec.
     *
     * @return array<string, array<string, string>> categoría => [clave => etiqueta]
     */
    public static function grouped(): array
    {
        $grupos = array_fill_keys(array_keys(self::categories()), []);

        foreach (self::TYPES as $key => $t) {
            $grupos[$t['category']][$key] = $t['label'];
        }

        return $grupos;
    }

    public static function label(string $key): string
    {
        return self::TYPES[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function category(string $key): ?string
    {
        return self::TYPES[$key]['category'] ?? null;
    }

    public static function categoryLabel(string $key): string
    {
        $categoria = self::category($key);

        return $categoria !== null ? (self::categories()[$categoria] ?? $categoria) : '—';
    }

    public static function defaultPaperSize(string $key): string
    {
        return self::TYPES[$key]['default_paper_size'] ?? '80mm';
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::TYPES);
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::TYPES);
    }
}
