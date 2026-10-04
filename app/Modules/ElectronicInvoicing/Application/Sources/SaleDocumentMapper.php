<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application\Sources;

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Support\TaxId;
use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfLine;
use App\Modules\ElectronicInvoicing\Domain\EcfParty;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;

/**
 * Una venta completada → e-CF canónico. Es el origen de Facturación A4, POS/Venta rápida,
 * Cotización → Factura y Mostrador: todos facturan una venta.
 *
 * Decisiones (no son reglas fiscales nuevas; son cómo se representa lo que ya pasó en la venta):
 *   · Tipo: serie B → e-CF del mismo concepto: B01 → 31, B02 → 32, B15 → 45 [IT §6.1]. Las notas
 *     (B03/B04) no se generan desde una venta: van por su propio circuito (33/34, fase 5b).
 *   · Precios con ITBIS incluido, como cobra la venta (`TaxCalculator`); el ITBIS de cada línea sale
 *     del indicador del producto (por omisión 18 %).
 *   · El descuento global del ticket se reparte entre las líneas en proporción a su importe, con el
 *     céntimo sobrante en la última: el e-CF solo tiene descuento por ítem en el mapeador actual, y
 *     así la suma de líneas es exactamente lo cobrado.
 *   · La propina queda FUERA del e-CF: no es venta gravada y el mapeador no tiene campo para ella
 *     (cómo se declara la propina legal en el e-CF queda en `pending_verification`).
 *   · Cantidades con 3 decimales (venta por peso) se aceptan si el tercero es 0; si no, el validador
 *     las rechaza con su mensaje: no se redondea a escondidas una cantidad vendida.
 */
final class SaleDocumentMapper
{
    public const TYPE_MAP = [
        'B01' => EcfType::CreditoFiscal,
        'B02' => EcfType::Consumo,
        'B15' => EcfType::Gubernamental,
    ];

    public function typeFor(NcfType $type): ?EcfType
    {
        return self::TYPE_MAP[$type->value] ?? null;
    }

    public function map(Sale $sale, EcfType $type, ?TaxId $taxId, ElectronicInvoicingSettings $settings): EcfDocument
    {
        $sale->loadMissing(['items.product' => fn ($q) => $q->withTrashed(), 'payments']);

        $declarado = bcsub((string) $sale->total, (string) ($sale->tip ?? '0'), 2);

        return new EcfDocument(
            type: $type,
            // Provisional: el definitivo lo pone el servicio al reservar el número.
            encf: $type->prefix().'0000000000',
            issueDate: CarbonImmutable::parse($sale->completed_at ?? now())->timezone((string) config('app.business_timezone')),
            emitter: new EcfParty(
                taxId: $settings->tax_id,
                legalName: $settings->legal_name,
                tradeName: $settings->trade_name,
                address: $settings->address,
                municipality: $settings->municipality,
                province: $settings->province,
                email: $settings->email,
            ),
            lines: $this->lineas($sale),
            pricesIncludeTax: true,
            buyer: $taxId !== null || $type !== EcfType::Consumo
                ? new EcfParty(taxId: $taxId?->value, legalName: $sale->customer_name)
                : null,
            // La venta a crédito no guarda fecha de vencimiento: no se inventa una para el documento
            // fiscal (FechaLimitePago se omite).
            paymentType: $this->esCredito($sale) ? 2 : 1,
            paymentForms: $this->formasDePago($sale, $declarado),
            declaredTotal: $declarado,
        );
    }

    /** @return list<EcfLine> */
    private function lineas(Sale $sale): array
    {
        $items = $sale->items->values();
        $importes = $items->map(fn ($i): string => bcsub(bcmul((string) $i->quantity, (string) $i->unit_price, 4), (string) ($i->discount ?? '0'), 2))->all();
        $repartos = $this->repartir((string) ($sale->discount_total ?? '0'), $importes);

        $lineas = [];

        foreach ($items as $n => $item) {
            $producto = $item->product;

            $lineas[] = new EcfLine(
                name: (string) ($producto?->name ?? 'Artículo'),
                quantity: $this->cantidad((string) $item->quantity),
                unitPrice: (string) $item->unit_price,
                indicator: BillingIndicator::tryFrom((int) ($producto?->getAttributes()['itbis_indicator'] ?? 1)) ?? BillingIndicator::Itbis1,
                discount: bcadd((string) ($item->discount ?? '0'), $repartos[$n], 2),
            );
        }

        return $lineas;
    }

    /**
     * Reparte un descuento global en proporción a cada importe; el resto de céntimos, en la última
     * línea con importe. Nunca deja una línea por debajo de cero.
     *
     * @param  list<string>  $importes
     * @return list<string>
     */
    private function repartir(string $descuento, array $importes): array
    {
        $ceros = array_fill(0, count($importes), '0.00');
        $total = array_reduce($importes, fn (string $s, string $i): string => bcadd($s, $i, 2), '0');

        if (bccomp($descuento, '0', 2) <= 0 || bccomp($total, '0', 2) <= 0) {
            return $ceros;
        }

        $descuento = bccomp($descuento, $total, 2) > 0 ? $total : $descuento;
        $repartido = '0';
        $ultima = null;

        foreach ($importes as $n => $importe) {
            if (bccomp($importe, '0', 2) <= 0) {
                continue;
            }

            $parte = bcdiv(bcadd(bcmul($descuento, bcdiv($importe, $total, 10), 10), '0.005', 10), '1', 2);
            $ceros[$n] = bccomp($parte, $importe, 2) > 0 ? $importe : $parte;
            $repartido = bcadd($repartido, $ceros[$n], 2);
            $ultima = $n;
        }

        if ($ultima !== null) {
            $ceros[$ultima] = bcadd($ceros[$ultima], bcsub($descuento, $repartido, 2), 2);
        }

        return $ceros;
    }

    /** «2.000» → «2», «1.250» → «1.25»; «1.255» se deja tal cual para que el validador lo diga. */
    private function cantidad(string $q): string
    {
        return str_contains($q, '.') ? rtrim(rtrim($q, '0'), '.') : $q;
    }

    private function esCredito(Sale $sale): bool
    {
        return (string) ($sale->payment_method?->value ?? $sale->payment_method) === PaymentMethod::Credit->value;
    }

    /**
     * Formas de pago [FMT TablaFormasPago]: el desglose real de la venta si lo hay; si no, la vía
     * única. La propina se descuenta de la forma de mayor importe (no forma parte del e-CF).
     *
     * @return list<array{form: int, amount: string}>
     */
    private function formasDePago(Sale $sale, string $declarado): array
    {
        $pagos = $sale->payments->isNotEmpty()
            ? $sale->payments->map(fn ($p): array => ['form' => $this->forma($p->method), 'amount' => (string) $p->amount])->all()
            : [['form' => $this->forma($sale->payment_method), 'amount' => (string) $sale->total]];

        // Agrupa por forma (dos tarjetas = una línea «tarjeta»).
        $porForma = [];
        foreach ($pagos as $p) {
            $porForma[$p['form']] = bcadd($porForma[$p['form']] ?? '0', $p['amount'], 2);
        }

        arsort($porForma, SORT_NUMERIC);
        $propina = bcsub((string) $sale->total, $declarado, 2);

        if (bccomp($propina, '0', 2) > 0) {
            $mayor = array_key_first($porForma);
            $porForma[$mayor] = bcsub($porForma[$mayor], $propina, 2);
        }

        $formas = [];
        foreach ($porForma as $forma => $monto) {
            if (bccomp($monto, '0', 2) > 0) {
                $formas[] = ['form' => (int) $forma, 'amount' => $monto];
            }
        }

        return $formas;
    }

    /** [FMT FormaPago]: 1 efectivo · 2 cheque/transferencia/depósito · 3 tarjeta · 4 venta a crédito. */
    private function forma(mixed $metodo): int
    {
        $valor = $metodo instanceof PaymentMethod ? $metodo : PaymentMethod::tryFrom((string) $metodo);

        return match ($valor) {
            PaymentMethod::Cash => 1,
            PaymentMethod::Transfer, PaymentMethod::Check => 2,
            PaymentMethod::Card => 3,
            PaymentMethod::Credit => 4,
            default => 8,
        };
    }
}
