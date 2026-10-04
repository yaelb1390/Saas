<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Controllers;

use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Support\EntregaDeArchivo;
use App\Modules\ElectronicInvoicing\Application\ElectronicInvoiceService;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoiceFile;
use App\Modules\ElectronicInvoicing\Storage\FiscalDocumentStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Los e-CF emitidos: lista, ficha (archivos, respuestas de la DGII, bitácora) y las dos acciones que
 * un usuario puede pedir a mano: reintentar el envío y consultar el resultado. El aislamiento por
 * empresa lo da el scope de tenant del modelo (un id ajeno no existe).
 */
final class ElectronicDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $documentos = DbTable::existe('electronic_invoices')
            ? ElectronicInvoice::query()
                ->when($request->filled('estado'), fn ($q) => $q->where('status', (string) $request->input('estado')))
                ->when($request->filled('tipo'), fn ($q) => $q->where('ecf_type', (int) $request->input('tipo')))
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($s) => $s
                    ->whereLike('e_ncf', '%'.$request->input('q').'%')
                    ->orWhereLike('buyer_name', '%'.$request->input('q').'%')
                    ->orWhereLike('buyer_tax_id', '%'.$request->input('q').'%')))
                ->latest('id')
                ->paginate(20)
                ->withQueryString()
            : null;

        return view('panel.e-invoicing.documents', [
            'documentos' => $documentos,
            'estados' => EcfStatus::cases(),
            'tipos' => EcfType::cases(),
        ]);
    }

    public function show(ElectronicInvoice $document): View
    {
        $document->load(['files' => fn ($q) => $q->orderBy('id'), 'responses' => fn ($q) => $q->orderBy('id'), 'auditLogs' => fn ($q) => $q->orderBy('id')]);

        return view('panel.e-invoicing.document', [
            'doc' => $document,
            'puedeReintentar' => in_array($document->status, [EcfStatus::PendienteEnvio, EcfStatus::Contingencia, EcfStatus::Error], true)
                && $document->file($document->sends_summary ? 'rfce_firmado' : 'firmado') !== null,
            'puedeConsultar' => $document->status === EcfStatus::Recibido && $document->track_id !== null,
        ]);
    }

    /** Descarga un XML del documento, comprobando antes que sigue siendo el que se guardó. */
    public function file(ElectronicInvoice $document, ElectronicInvoiceFile $file, FiscalDocumentStore $store): Response
    {
        abort_unless((int) $file->electronic_invoice_id === (int) $document->id, 404);

        try {
            $bytes = $store->get($file);
        } catch (Throwable $e) {
            abort(409, $e->getMessage());
        }

        $nombre = EntregaDeArchivo::nombreSeguro("{$document->e_ncf}-{$file->kind}.xml");

        return response($bytes, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function resend(ElectronicInvoice $document, Request $request, ElectronicInvoiceService $service): RedirectResponse
    {
        if (! in_array($document->status, [EcfStatus::PendienteEnvio, EcfStatus::Contingencia, EcfStatus::Error], true)) {
            return back()->with('panel_error', 'Este documento no está pendiente de envío.');
        }

        $doc = $service->send($document, $request->user()?->id, $request->ip());

        return back()->with($doc->status === EcfStatus::Error ? 'panel_error' : 'panel_ok', "Estado de {$doc->e_ncf}: {$doc->status->label()}.");
    }

    public function query(ElectronicInvoice $document, Request $request, ElectronicInvoiceService $service): RedirectResponse
    {
        if ($document->status !== EcfStatus::Recibido) {
            return back()->with('panel_error', 'Solo se consulta un documento recibido por la DGII que espera su resultado.');
        }

        $doc = $service->query($document, $request->user()?->id);

        return back()->with('panel_ok', "Estado de {$doc->e_ncf}: {$doc->status->label()}.");
    }
}
