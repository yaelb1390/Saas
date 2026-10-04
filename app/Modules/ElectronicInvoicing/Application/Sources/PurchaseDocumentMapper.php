<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application\Sources;

use App\Modules\Billing\Models\PurchaseInvoice;
use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfLine;
use App\Modules\ElectronicInvoicing\Domain\EcfParty;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Una compra registrada en «Compras 606» → comprobante que emite la propia empresa al comprar a quien
 * no puede darle uno: Compras (41) o Gastos menores (43) [IT §6.1].
 *
 *   · En el 41 el «comprador» del XML es el PROVEEDOR informal (su cédula/RNC y nombre): el emisor es
 *     la empresa que compra. Lo exige el XSD (lo comprueba el validador).
 *   · El monto del 606 es la base SIN ITBIS: precios sin impuesto incluido. Con ITBIS informado, la línea
 *     va gravada al 18 % y el total calculado tiene que cuadrar con monto + ITBIS (si no, el validador lo
 *     dice: no se reparte un ITBIS que no sale de la tasa); sin ITBIS, exenta.
 *   · El 43 solo admite exentos [FMT nota 50]: con ITBIS se rechaza.
 *   · Las retenciones del 606 (ITBIS e ISR retenidos) van en la línea; el ISR solo en servicios, y si es
 *     servicio lo dice el usuario (el tipo de bien/servicio del 606 no basta para saberlo).
 */
final class PurchaseDocumentMapper
{
    public const KINDS = [
        'compras' => EcfType::Compras,
        'gastos_menores' => EcfType::GastosMenores,
    ];

    public function typeFor(string $kind): EcfType
    {
        return self::KINDS[$kind] ?? throw new DomainException("Tipo de comprobante de compra desconocido: {$kind}.");
    }

    public function map(PurchaseInvoice $p, EcfType $type, bool $isService, ElectronicInvoicingSettings $settings): EcfDocument
    {
        $monto = bcadd((string) $p->amount, '0', 2);
        $itbis = bcadd((string) ($p->itbis ?? '0'), '0', 2);
        $conItbis = bccomp($itbis, '0', 2) > 0;

        $retenidoItbis = bccomp((string) ($p->itbis_retenido ?? '0'), '0', 2) > 0 ? bcadd((string) $p->itbis_retenido, '0', 2) : null;
        $retenidoIsr = bccomp((string) ($p->isr_retenido ?? '0'), '0', 2) > 0 ? bcadd((string) $p->isr_retenido, '0', 2) : null;

        $tipoBien = $p->goods_services_type;
        $nombre = $tipoBien !== null ? mb_substr(preg_replace('/^\d+ · /', '', $tipoBien->label()) ?? '', 0, 80) : 'Compra';

        return new EcfDocument(
            type: $type,
            encf: $type->prefix().'0000000000',
            issueDate: CarbonImmutable::parse($p->invoice_date ?? now()),
            emitter: new EcfParty(
                taxId: $settings->tax_id,
                legalName: $settings->legal_name,
                tradeName: $settings->trade_name,
                address: $settings->address,
                municipality: $settings->municipality,
                province: $settings->province,
                email: $settings->email,
            ),
            lines: [new EcfLine(
                name: $nombre,
                quantity: '1',
                unitPrice: $monto,
                indicator: $conItbis ? BillingIndicator::Itbis1 : BillingIndicator::Exento,
                isService: $isService,
                itbisWithheld: $retenidoItbis,
                isrWithheld: $retenidoIsr,
            )],
            pricesIncludeTax: false,
            buyer: $type === EcfType::Compras || filled($p->provider_tax_id) || filled($p->provider_name)
                ? new EcfParty(taxId: preg_replace('/\D/', '', (string) $p->provider_tax_id) ?: null, legalName: $p->provider_name)
                : null,
            paymentType: $p->payment_method === 'credit' ? 2 : 1,
            // Lo que de verdad se le pagó: el total menos lo retenido (que va a la DGII, no al proveedor).
            paymentForms: [['form' => $this->forma((string) $p->payment_method), 'amount' => bcsub(bcsub(bcadd($monto, $itbis, 2), $retenidoItbis ?? '0', 2), $retenidoIsr ?? '0', 2)]],
            declaredTotal: bcadd($monto, $itbis, 2),
        );
    }

    /** [FMT FormaPago] con las formas del 606. */
    private function forma(string $metodo): int
    {
        return match ($metodo) {
            'cash' => 1,
            'check', 'transfer' => 2,
            'card' => 3,
            'credit' => 4,
            'swap' => 6,
            'credit_note' => 7,
            default => 8,
        };
    }
}
