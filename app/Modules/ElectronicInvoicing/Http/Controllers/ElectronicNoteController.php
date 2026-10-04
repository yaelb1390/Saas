<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Controllers;

use App\Modules\Billing\Models\Invoice;
use App\Modules\ElectronicInvoicing\Application\Sources\InvoiceNoteService;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Nota de crédito (34) o débito (33) por importe sobre una factura con e-CF. La lógica vive en
 * InvoiceNoteService; aquí solo se valida la entrada y se traduce el resultado.
 */
final class ElectronicNoteController extends Controller
{
    public function store(Request $request, Invoice $invoice, InvoiceNoteService $notes): RedirectResponse
    {
        $datos = $request->validate([
            'type' => ['required', Rule::in([EcfType::NotaCredito->value, EcfType::NotaDebito->value])],
            'amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'indicator' => ['required', Rule::in(array_map(fn (BillingIndicator $i): int => $i->value, BillingIndicator::forProducts()))],
            'reason' => ['required', 'string', 'min:3', 'max:90'],
        ], [
            'amount.regex' => 'El importe debe ser un número con hasta 2 decimales.',
            'amount.not_in' => 'El importe debe ser mayor que cero.',
        ]);

        if ($invoice->isCancelled()) {
            return back()->with('panel_error', 'La factura está anulada.');
        }

        try {
            $nota = DB::transaction(fn () => $notes->forAmount(
                $invoice,
                EcfType::from((int) $datos['type']),
                $datos['amount'],
                BillingIndicator::from((int) $datos['indicator']),
                $datos['reason'],
            ));
        } catch (Throwable $e) {
            return back()->withInput()->with('panel_error', 'No se pudo emitir la nota: '.$e->getMessage());
        }

        return back()->with('panel_ok', "Nota {$nota->e_ncf} emitida sobre {$invoice->ncf}.");
    }
}
