<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application\Sources;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\TaxId;
use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Application\ElectronicInvoiceService;
use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfLine;
use App\Modules\ElectronicInvoicing\Domain\EcfParty;
use App\Modules\ElectronicInvoicing\Domain\EcfReference;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Notas de crédito (34) y débito (33) sobre la factura de una venta [FMT sección F].
 *
 *   · Anular una factura cuyo comprobante es un e-CF = nota de crédito por el TOTAL, con las mismas
 *     líneas y código de modificación 1 «Anula el NCF modificado». Un e-CF aceptado no desaparece:
 *     se revierte.
 *   · Nota por importe (descuento posterior, devolución parcial, cargo adicional): una línea con el
 *     importe y el motivo, código 3 «Corrige montos del NCF modificado».
 *
 * La nota va en el MISMO ambiente que el e-CF que modifica y lo referencia por su e-NCF, su fecha y
 * su total (la suma de notas de crédito no puede superarlo [FMT campo 110 d)]; lo comprueba el
 * validador). Se envía después de confirmar la transacción, como las facturas.
 */
final class InvoiceNoteService
{
    public const SOURCE_CREDIT = 'credit_note';

    public const SOURCE_DEBIT = 'debit_note';

    public function __construct(
        private readonly SaleDocumentMapper $mapper,
        private readonly ElectronicInvoiceService $service,
    ) {}

    /** El e-CF de la factura (el comprobante en modo real, la prueba en paralelo) o null. */
    public function original(Invoice $invoice): ?ElectronicInvoice
    {
        $id = $invoice->getAttributes()['electronic_invoice_id'] ?? null;

        return $id === null ? null : ElectronicInvoice::query()->withoutGlobalScopes()->find($id);
    }

    /**
     * Nota de crédito por el total. Null si no hace falta: la factura no tiene e-CF, o la DGII lo
     * rechazó (nunca tuvo validez, no hay nada que revertir).
     */
    public function creditForCancellation(Invoice $invoice, string $reason): ?ElectronicInvoice
    {
        $original = $this->original($invoice);

        if ($original === null || $original->status === EcfStatus::Rechazado) {
            return null;
        }

        [$company, $settings] = $this->contexto($invoice, $original);
        $sale = $invoice->sale()->withTrashed()->first() ?? throw new DomainException('La factura no tiene la venta de la que salió.');

        $lineas = $this->mapper->map($sale, EcfType::NotaCredito, null, $settings)->lines;

        return $this->emitir($company, $settings, $invoice, $original, EcfType::NotaCredito, $lineas, 1, $reason);
    }

    /** Nota por importe: 34 (crédito) o 33 (débito). */
    public function forAmount(Invoice $invoice, EcfType $type, string $amount, BillingIndicator $indicator, string $reason): ElectronicInvoice
    {
        if (! in_array($type, [EcfType::NotaCredito, EcfType::NotaDebito], true)) {
            throw new DomainException('Solo notas de crédito o de débito.');
        }

        $original = $this->original($invoice) ?? throw new DomainException('Esta factura no tiene comprobante electrónico.');

        if ($original->status === EcfStatus::Rechazado) {
            throw new DomainException("El e-CF {$original->e_ncf} fue rechazado por la DGII: no se le pueden hacer notas.");
        }

        [$company, $settings] = $this->contexto($invoice, $original);
        $linea = new EcfLine(name: mb_substr($reason, 0, 80), quantity: '1', unitPrice: $amount, indicator: $indicator, isService: true);

        return $this->emitir($company, $settings, $invoice, $original, $type, [$linea], 3, $reason);
    }

    /** @param  list<EcfLine>  $lineas */
    private function emitir(
        Company $company,
        ElectronicInvoicingSettings $settings,
        Invoice $invoice,
        ElectronicInvoice $original,
        EcfType $type,
        array $lineas,
        int $codigo,
        string $motivo,
    ): ElectronicInvoice {
        $acreditado = (string) ElectronicInvoice::query()->withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('source_type', self::SOURCE_CREDIT)
            ->where('source_id', $invoice->id)
            ->where('status', '!=', EcfStatus::Rechazado->value)
            ->sum('total');

        $taxId = TaxId::tryParse((string) $invoice->customer_tax_id);

        $doc = new EcfDocument(
            type: $type,
            encf: $type->prefix().'0000000000',
            issueDate: CarbonImmutable::now((string) config('app.business_timezone')),
            emitter: new EcfParty(
                taxId: $settings->tax_id,
                legalName: $settings->legal_name,
                tradeName: $settings->trade_name,
                address: $settings->address,
                municipality: $settings->municipality,
                province: $settings->province,
                email: $settings->email,
            ),
            lines: $lineas,
            buyer: $taxId !== null || filled($invoice->customer_name)
                ? new EcfParty(taxId: $taxId?->value, legalName: $invoice->customer_name)
                : null,
            reference: new EcfReference(
                modifiedNcf: $original->e_ncf,
                modifiedDate: CarbonImmutable::parse($original->issue_date),
                code: $codigo,
                reason: mb_substr($motivo, 0, 90),
                modifiedTotal: $type === EcfType::NotaCredito ? (string) $original->total : null,
                alreadyCredited: bcadd($acreditado, '0', 2),
            ),
        );

        $nota = DB::transaction(fn (): ElectronicInvoice => $this->service->prepare(
            $company, $doc, $type === EcfType::NotaCredito ? self::SOURCE_CREDIT : self::SOURCE_DEBIT, (int) $invoice->id, auth()->id(),
        ));

        if ($nota->status !== EcfStatus::PendienteEnvio) {
            throw new DomainException((string) ($nota->last_error ?? 'No se pudo firmar la nota.'));
        }

        $this->service->sendAfterCommit($nota);

        return $nota;
    }

    /**
     * La empresa y sus ajustes, comprobando que la nota irá al mismo ambiente que el original: una
     * nota de producción no puede referenciar un e-CF de pruebas ni al revés.
     *
     * @return array{0: Company, 1: ElectronicInvoicingSettings}
     */
    private function contexto(Invoice $invoice, ElectronicInvoice $original): array
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $settings = ElectronicInvoicingSettings::paraEmpresa($company);

        if ($settings->environment !== $original->environment) {
            throw new DomainException("El e-CF {$original->e_ncf} es del ambiente {$original->environment->label()} y la empresa está ahora en {$settings->environment->label()}.");
        }

        return [$company, $settings];
    }
}
