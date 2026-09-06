<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\GoodsServicesType;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PurchaseInvoice;
use App\Modules\Billing\Support\TaxId;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Sales\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Genera los envíos de datos de la DGII en formato TXT delimitado por pipes.
 *
 *  - 607: Ventas de Bienes y Servicios (comprobantes emitidos del período).
 *  - 608: Comprobantes Anulados (NCF inutilizados, con su código de anulación).
 *
 * Ambos son mensuales: el período se informa como AAAAMM. Una factura anulada NO va en el 607;
 * va en el 608. Esa exclusión es la regla que más errores de validación provoca en la DGII.
 */
final class DgiiReportService
{
    /** Ingresos por operaciones (ventas del giro del negocio). */
    private const TIPO_INGRESO_OPERACIONES = '01';

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * Formato 607 — Ventas de Bienes y Servicios.
     */
    public function sales607(Carbon $period): string
    {
        $invoices = $this->invoicesOfPeriod($period)
            ->where('status', InvoiceStatus::Issued)
            ->values();

        $lines = $invoices->map(fn (Invoice $invoice): string => $this->line607($invoice));

        return $this->render('607', $period, $invoices->count(), $lines->all());
    }

    /**
     * Formato 608 — Comprobantes Anulados.
     */
    public function cancelled608(Carbon $period): string
    {
        $invoices = $this->invoicesOfPeriod($period)
            ->where('status', InvoiceStatus::Cancelled)
            ->values();

        $lines = $invoices->map(function (Invoice $invoice): string {
            $reason = $invoice->cancellation_code;

            return implode('|', [
                (string) $invoice->ncf,
                $this->date($invoice->issued_at),
                $reason === null ? '' : $reason->value,
            ]);
        });

        return $this->render('608', $period, $invoices->count(), $lines->all());
    }

    /**
     * Formato 606 — Compras de Bienes y Servicios (comprobantes recibidos de proveedores).
     */
    public function purchases606(Carbon $period): string
    {
        $purchases = $this->purchasesOfPeriod($period);

        $lines = $purchases->map(fn (PurchaseInvoice $p): string => $this->line606($p));

        return $this->render('606', $period, $purchases->count(), $lines->all());
    }

    /**
     * Mismos datos del 606 pero en forma de tabla legible (encabezados + filas) para exportar a Excel.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    public function purchases606Table(Carbon $period): array
    {
        $headers = [
            'RNC/Cédula', 'Tipo ID', 'Tipo bien/servicio', 'NCF', 'NCF modificado',
            'Fecha comprobante', 'Fecha pago', 'Monto facturado', 'ITBIS facturado', 'ITBIS retenido',
            'Retención renta (ISR)', 'ISC', 'Otros impuestos', 'Propina legal', 'Forma de pago', 'Proveedor',
        ];

        $rows = $this->purchasesOfPeriod($period)->map(fn (PurchaseInvoice $p): array => [
            (string) $p->provider_tax_id,
            $p->provider_tax_id_kind->label(),
            $p->goods_services_type->label(),
            (string) $p->ncf,
            (string) $p->ncf_modified,
            $this->date($p->invoice_date),
            $this->date($p->payment_date),
            $this->money($p->amount),
            $this->money($p->itbis),
            $this->money($p->itbis_retenido),
            $this->money($p->isr_retenido),
            $this->money($p->isc),
            $this->money($p->other_taxes),
            $this->money($p->tip),
            $this->paymentLabel((string) $p->payment_method),
            (string) $p->provider_name,
        ])->all();

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Línea de detalle del 606 (23 columnas, pipe-delimitadas).
     */
    private function line606(PurchaseInvoice $p): string
    {
        $rnc = preg_replace('/\D/', '', (string) $p->provider_tax_id) ?? '';
        $amount = (string) $p->amount;
        $isService = $this->isService($p->goods_services_type);

        return implode('|', [
            $rnc,                                        // 1  RNC/Cédula del proveedor
            $rnc === '' ? '' : $p->provider_tax_id_kind->value, // 2  Tipo de identificación
            $p->goods_services_type->value,              // 3  Tipo de bienes y servicios comprados
            (string) $p->ncf,                            // 4  NCF
            (string) $p->ncf_modified,                   // 5  NCF ó documento modificado
            $this->date($p->invoice_date),               // 6  Fecha del comprobante
            $this->date($p->payment_date),               // 7  Fecha de pago
            $isService ? $this->money($p->amount) : $this->money(0), // 8  Monto facturado en servicios
            $isService ? $this->money(0) : $this->money($p->amount), // 9  Monto facturado en bienes
            $this->money($amount),                       // 10 Total monto facturado
            $this->money($p->itbis),                     // 11 ITBIS facturado
            $this->money($p->itbis_retenido),            // 12 ITBIS retenido
            $this->money(0),                             // 13 ITBIS sujeto a proporcionalidad
            $this->money(0),                             // 14 ITBIS llevado al costo
            $this->money(0),                             // 15 ITBIS por adelantar
            $this->money(0),                             // 16 ITBIS percibido en compras
            '',                                          // 17 Tipo de retención en ISR
            $this->money($p->isr_retenido),              // 18 Monto retención renta
            $this->money(0),                             // 19 ISR percibido en compras
            $this->money($p->isc),                       // 20 Impuesto selectivo al consumo
            $this->money($p->other_taxes),               // 21 Otros impuestos/tasas
            $this->money($p->tip),                       // 22 Monto propina legal
            $this->paymentCode((string) $p->payment_method), // 23 Forma de pago
        ]);
    }

    /** Los tipos 02, 03, 05, 07 y 11 se declaran como servicios; el resto como bienes. */
    private function isService(GoodsServicesType $type): bool
    {
        return in_array($type, [
            GoodsServicesType::TrabajosSuministrosServicios,
            GoodsServicesType::Arrendamientos,
            GoodsServicesType::Representacion,
            GoodsServicesType::Financieros,
            GoodsServicesType::Seguros,
        ], true);
    }

    /** Código DGII de la forma de pago (columna 23). */
    private function paymentCode(string $method): string
    {
        return match ($method) {
            'cash' => '01',
            'check', 'transfer' => '02',
            'card' => '03',
            'credit' => '04',
            'swap' => '05',
            'credit_note' => '06',
            default => '07',
        };
    }

    private function paymentLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'Efectivo',
            'check' => 'Cheque',
            'transfer' => 'Transferencia',
            'card' => 'Tarjeta',
            'credit' => 'Crédito',
            'swap' => 'Permuta',
            'credit_note' => 'Nota de crédito',
            default => 'Otras',
        };
    }

    /**
     * @return Collection<int, PurchaseInvoice>
     */
    private function purchasesOfPeriod(Carbon $period): Collection
    {
        return PurchaseInvoice::query()
            ->whereBetween('invoice_date', [
                $period->copy()->startOfMonth()->toDateString(),
                $period->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('invoice_date')
            ->orderBy('ncf')
            ->get();
    }

    /**
     * Cabecera + detalle. La DGII espera el RNC del informante y la cantidad de registros.
     */
    private function render(string $format, Carbon $period, int $count, array $lines): string
    {
        $header = implode('|', [
            $format,
            $this->companyTaxId(),
            $period->format('Ym'),
            (string) $count,
        ]);

        return implode("\r\n", [$header, ...$lines])."\r\n";
    }

    private function line607(Invoice $invoice): string
    {
        $taxId = TaxId::tryParse($invoice->customer_tax_id);

        // Consumidor final: la DGII admite el comprobante de consumo sin identificar al cliente,
        // y en ese caso las dos primeras columnas viajan vacías.
        $total = (string) $invoice->total;
        $payments = $this->paymentColumns($invoice, $total);

        return implode('|', [
            $taxId === null ? '' : $taxId->value,       // 1  RNC/Cédula
            $taxId === null ? '' : $taxId->kind->value, // 2  Tipo de identificación
            (string) $invoice->ncf,                     // 3  NCF
            '',                                         // 4  NCF modificado (notas de crédito/débito)
            self::TIPO_INGRESO_OPERACIONES,             // 5  Tipo de ingreso
            $this->date($invoice->issued_at),           // 6  Fecha del comprobante
            '',                                         // 7  Fecha de retención
            $this->money($invoice->subtotal),           // 8  Monto facturado (sin ITBIS)
            $this->money($invoice->tax),                // 9  ITBIS facturado
            $this->money(0),                            // 10 ITBIS retenido por terceros
            $this->money(0),                            // 11 ITBIS percibido
            $this->money(0),                            // 12 Retención de renta por terceros
            $this->money(0),                            // 13 ISR percibido
            $this->money(0),                            // 14 Impuesto selectivo al consumo
            $this->money(0),                            // 15 Otros impuestos/tasas
            $this->money(0),                            // 16 Monto propina legal
            ...$payments,                               // 17-23 Formas de pago
        ]);
    }

    /**
     * Columnas 17 a 23: el importe se declara en la forma de pago usada y 0.00 en el resto.
     *
     * @return array<int, string>
     */
    private function paymentColumns(Invoice $invoice, string $total): array
    {
        $sale = $invoice->sale;

        // Efectivo, Cheque/Transferencia, Tarjeta, Crédito, Bonos, Permuta, Otras.
        $importes = array_fill(0, 7, '0');

        if ($sale === null) {
            // Una factura sin venta detrás: se conserva lo de siempre, todo a efectivo.
            $importes[0] = $total;
        } else {
            /*
             * SE REPARTE, ya no se elige una sola columna.
             *
             * Antes se miraba `sales.payment_method` y el total entero se declaraba en esa columna.
             * Con un cobro repartido eso sería declarar mal ante la DGII: mil pesos cobrados 600 en
             * efectivo y 400 con tarjeta se habrían declarado como mil en efectivo.
             *
             * El desglose pasa por `Sale::desglose()`, que sabe sintetizar el de las ventas
             * anteriores a que existiera el reparto: para todas ellas el resultado es byte a byte el
             * mismo que antes.
             */
            foreach ($sale->desglose()->pagos() as $pago) {
                $columna = match ($pago->method) {
                    PaymentMethod::Cash => 0,
                    PaymentMethod::Transfer, PaymentMethod::Check => 1,
                    PaymentMethod::Card => 2,
                    PaymentMethod::Credit => 3,
                };

                $importes[$columna] = bcadd($importes[$columna], $pago->amount, 2);
            }
        }

        return array_map(fn (string $importe): string => $this->money($importe), $this->cuadrar($importes, $total));
    }

    /**
     * Fuerza que las columnas 17 a 23 sumen EXACTAMENTE el total facturado.
     *
     * La DGII lo valida, y un céntimo de diferencia tumba el envío del mes entero. Puede aparecer por
     * redondeo, o porque el total de la factura no coincida con el de la venta —hoy pasa cuando hay
     * propina: la factura la incluye en el total pero no en base+ITBIS—.
     *
     * El resto se ajusta en la columna de MAYOR importe, que es donde menos se nota y donde no puede
     * convertir un cero en un número: declarar un peso en «Bonos» porque ahí cuadraba sería peor que
     * el descuadre.
     *
     * @param  array<int, string>  $importes
     * @return array<int, string>
     */
    private function cuadrar(array $importes, string $total): array
    {
        $suma = '0';

        foreach ($importes as $importe) {
            $suma = bcadd($suma, $importe, 2);
        }

        $resto = bcsub($total, $suma, 2);

        if (bccomp($resto, '0', 2) === 0) {
            return $importes;
        }

        $mayor = 0;

        foreach ($importes as $i => $importe) {
            if (bccomp($importe, $importes[$mayor], 2) > 0) {
                $mayor = $i;
            }
        }

        $importes[$mayor] = bcadd($importes[$mayor], $resto, 2);

        return $importes;
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function invoicesOfPeriod(Carbon $period): Collection
    {
        return Invoice::query()
            /*
             * Con sus pagos: el 607 los recorre por factura, y sin esto sería una consulta por cada
             * comprobante del mes. La guarda está porque en producción las migraciones se aplican a
             * mano y pedir una relación cuya tabla aún no existe tumbaría el envío entero.
             */
            ->with(DbTable::existe('sale_payments') ? ['sale.payments'] : ['sale'])
            ->whereBetween('issued_at', [
                $period->copy()->startOfMonth(),
                $period->copy()->endOfMonth(),
            ])
            ->orderBy('ncf')
            ->get();
    }

    private function companyTaxId(): string
    {
        $companyId = $this->currentCompany->id();

        $taxId = $companyId === null
            ? null
            : Company::query()->whereKey($companyId)->value('tax_id');

        return preg_replace('/\D/', '', (string) $taxId) ?? '';
    }

    private function date(?Carbon $date): string
    {
        return $date?->format('Ymd') ?? '';
    }

    private function money(string|float|int $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
