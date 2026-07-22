<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\InvoiceResource;
use App\Modules\Billing\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Comprobantes fiscales vía API v1 (solo lectura). La emisión y anulación se hacen desde el
 * panel, donde viven las reglas de secuencias de NCF. Aislado por la empresa del token.
 */
final class InvoiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = Invoice::query()
            ->when($request->query('q'), fn ($query, $q) => $query->whereLike('ncf', "%{$q}%"))
            ->latest()
            ->paginate((int) $request->integer('per_page', 25));

        return InvoiceResource::collection($invoices);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice);
    }
}
