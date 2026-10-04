<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application\Sources;

use App\Modules\Billing\Contracts\ElectronicInvoicingHook;
use App\Modules\Billing\Enums\CancellationReason;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PurchaseInvoice;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Billing\Support\TaxId;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Support\DbTable;
use App\Modules\ElectronicInvoicing\Application\ElectronicInvoiceService;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EmissionMode;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Printing\Timbre;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La facturación (serie B) y la electrónica, unidas por el contrato de Billing.
 *
 *   · apagado: no hace nada.
 *   · sombra:  la factura B se emite como siempre y, DESPUÉS, se prepara un e-CF de prueba con la misma
 *              venta en un punto de guardado propio: si falla, se deshace solo él, queda constancia y
 *              la venta sigue.
 *   · real:    el e-CF ES el comprobante. Si no se puede emitir (datos inválidos, sin certificado, sin
 *              secuencia), la factura no se emite —igual que hoy sin secuencia B—, con el motivo.
 *
 * En los dos modos el envío a la DGII va DESPUÉS de confirmar la transacción de la venta.
 */
final class BillingBridge implements ElectronicInvoicingHook
{
    public function __construct(
        private readonly SaleDocumentMapper $mapper,
        private readonly ElectronicInvoiceService $service,
        private readonly InvoiceNoteService $notes,
        private readonly PurchaseDocumentMapper $purchases,
    ) {}

    public function replaceNcf(Sale $sale, NcfType $type, ?TaxId $taxId): ?array
    {
        [$company, $settings] = $this->contexto((int) $sale->company_id);

        if ($settings === null || $settings->emissionMode() !== EmissionMode::Real) {
            return null;
        }

        $tipo = $this->mapper->typeFor($type)
            ?? throw InvoiceException::electronic("el comprobante «{$type->label()}» todavía no se emite como e-CF desde una venta.");

        try {
            $ecf = $this->service->prepare($company, $this->mapper->map($sale, $tipo, $taxId, $settings), 'sale', (int) $sale->id, auth()->id());
        } catch (Throwable $e) {
            throw InvoiceException::electronic($e->getMessage());
        }

        if ($ecf->status !== EcfStatus::PendienteEnvio) {
            throw InvoiceException::electronic((string) ($ecf->last_error ?? 'no se pudo firmar el documento.'));
        }

        $this->enviarAlConfirmar($ecf);

        return ['ncf' => $ecf->e_ncf, 'electronic_invoice_id' => (int) $ecf->id];
    }

    public function afterInvoiceCreated(Invoice $invoice, Sale $sale, ?TaxId $taxId): void
    {
        try {
            $vinculado = $invoice->getAttributes()['electronic_invoice_id'] ?? null;

            if ($vinculado !== null) {
                // Modo real: el e-CF ya existe; se apunta a la factura como su origen.
                ElectronicInvoice::query()->withoutGlobalScopes()->whereKey($vinculado)
                    ->update(['source_type' => 'invoice', 'source_id' => $invoice->id]);

                return;
            }

            [$company, $settings] = $this->contexto((int) $sale->company_id);
            $tipo = $this->mapper->typeFor($invoice->type);

            if ($settings === null || $settings->emissionMode() !== EmissionMode::Sombra || $tipo === null) {
                return;
            }

            // Punto de guardado propio: un fallo deshace solo el e-CF de prueba, nunca la factura B.
            $ecf = DB::transaction(fn (): ElectronicInvoice => $this->service->prepare(
                $company, $this->mapper->map($sale, $tipo, $taxId, $settings), 'invoice', (int) $invoice->id, auth()->id(),
            ));

            $invoice->forceFill(['electronic_invoice_id' => $ecf->id])->save();

            if ($ecf->status === EcfStatus::PendienteEnvio) {
                $this->enviarAlConfirmar($ecf);
            }
        } catch (Throwable $e) {
            SystemEvent::registrar(
                type: 'ecf.shadow_failed',
                message: "e-CF de prueba no generado para la factura {$invoice->ncf}",
                contexto: ['factura' => $invoice->id, 'motivo' => mb_substr($e->getMessage(), 0, 500)],
                level: SystemEvent::AVISO,
            );
        }
    }

    public function replacePurchaseNcf(PurchaseInvoice $purchase, string $kind, bool $isService): ?array
    {
        [$company, $settings] = $this->contexto((int) app(CurrentCompany::class)->id(), 'purchase_invoices');

        if ($settings === null || $settings->emissionMode() !== EmissionMode::Real) {
            return null;
        }

        try {
            $doc = $this->purchases->map($purchase, $this->purchases->typeFor($kind), $isService, $settings);
            $ecf = $this->service->prepare($company, $doc, 'purchase_invoice', null, auth()->id());
        } catch (Throwable $e) {
            throw InvoiceException::electronic($e->getMessage());
        }

        if ($ecf->status !== EcfStatus::PendienteEnvio) {
            throw InvoiceException::electronic((string) ($ecf->last_error ?? 'no se pudo firmar el documento.'));
        }

        $this->enviarAlConfirmar($ecf);

        return ['ncf' => $ecf->e_ncf, 'electronic_invoice_id' => (int) $ecf->id];
    }

    public function afterPurchaseCreated(PurchaseInvoice $purchase, string $kind, bool $isService): void
    {
        try {
            $vinculado = $purchase->getAttributes()['electronic_invoice_id'] ?? null;

            if ($vinculado !== null) {
                ElectronicInvoice::query()->withoutGlobalScopes()->whereKey($vinculado)
                    ->update(['source_type' => 'purchase_invoice', 'source_id' => $purchase->id]);

                return;
            }

            [$company, $settings] = $this->contexto((int) $purchase->company_id, 'purchase_invoices');

            if ($settings === null || $settings->emissionMode() !== EmissionMode::Sombra) {
                return;
            }

            $ecf = DB::transaction(fn (): ElectronicInvoice => $this->service->prepare(
                $company, $this->purchases->map($purchase, $this->purchases->typeFor($kind), $isService, $settings),
                'purchase_invoice', (int) $purchase->id, auth()->id(),
            ));

            $purchase->forceFill(['electronic_invoice_id' => $ecf->id])->save();

            if ($ecf->status === EcfStatus::PendienteEnvio) {
                $this->enviarAlConfirmar($ecf);
            }
        } catch (Throwable $e) {
            SystemEvent::registrar(
                type: 'ecf.shadow_failed',
                message: "e-CF de prueba no generado para la compra {$purchase->ncf}",
                contexto: ['compra' => $purchase->id, 'motivo' => mb_substr($e->getMessage(), 0, 500)],
                level: SystemEvent::AVISO,
            );
        }
    }

    /**
     * La empresa y sus ajustes si la facturación electrónica puede participar; si no, [null, null].
     * `$tabla` es la del documento de origen, que debe tener ya su columna `electronic_invoice_id`.
     *
     * @return array{0: Company|null, 1: ElectronicInvoicingSettings|null}
     */
    private function contexto(int $companyId, string $tabla = 'invoices'): array
    {
        // El código llega antes que la migración: sin la columna del modo, nada cambia.
        if (! DbTable::tieneColumna('electronic_invoicing_settings', 'emission_mode')
            || ! DbTable::tieneColumna($tabla, 'electronic_invoice_id')) {
            return [null, null];
        }

        $company = Company::query()->find($companyId);

        if ($company === null || ! $company->hasModule('e_invoicing')) {
            return [null, null];
        }

        return [$company, ElectronicInvoicingSettings::paraEmpresa($company)];
    }

    public function beforeCancel(Invoice $invoice, CancellationReason $reason, ?string $note): void
    {
        if (($invoice->getAttributes()['electronic_invoice_id'] ?? null) === null) {
            return;
        }

        $motivo = trim($reason->label().($note !== null && $note !== '' ? ': '.$note : ''));

        // Modo real: el comprobante ES el e-CF. Sin nota de crédito no hay anulación.
        if (str_starts_with((string) $invoice->ncf, 'E')) {
            try {
                $this->notes->creditForCancellation($invoice, $motivo);
            } catch (Throwable $e) {
                throw InvoiceException::electronic('no se pudo emitir la nota de crédito: '.$e->getMessage());
            }

            return;
        }

        // En paralelo: la anulación B va al 608 como siempre; la nota de prueba no puede impedirla.
        try {
            DB::transaction(fn () => $this->notes->creditForCancellation($invoice, $motivo));
        } catch (Throwable $e) {
            SystemEvent::registrar(
                type: 'ecf.shadow_failed',
                message: "Nota de crédito de prueba no generada al anular {$invoice->ncf}",
                contexto: ['factura' => $invoice->id, 'motivo' => mb_substr($e->getMessage(), 0, 500)],
                level: SystemEvent::AVISO,
            );
        }
    }

    public function printedRepresentation(Invoice $invoice): ?array
    {
        // Solo cuando el comprobante ES el e-CF (modo real). En paralelo el documento es la factura B.
        if (! str_starts_with((string) $invoice->ncf, 'E')) {
            return null;
        }

        try {
            $ecf = $this->notes->original($invoice);

            return $ecf === null ? null : app(Timbre::class)->for($ecf);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function enviarAlConfirmar(ElectronicInvoice $ecf): void
    {
        $this->service->sendAfterCommit($ecf);
    }
}
