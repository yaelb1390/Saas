<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoiceAuditLog;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicReceivedDocument;
use App\Modules\ElectronicInvoicing\Receiver\CommercialApprovalService;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use Illuminate\Support\Carbon;
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

    /**
     * Bitácora de toda la empresa, filtrable. Las filas solo se añaden (ElectronicInvoiceAuditLog):
     * lo que se ve aquí es la historia completa, con usuario e IP.
     */
    public function audit(Request $request): View
    {
        $filtros = $request->validate([
            'encf' => ['nullable', 'string', 'max:13'],
            'usuario' => ['nullable', 'integer'],
            'accion' => ['nullable', 'string', 'max:60'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $filas = DbTable::existe('electronic_invoice_audit_logs')
            ? ElectronicInvoiceAuditLog::query()
                ->with('user:id,name')
                ->when($filtros['encf'] ?? null, fn ($q, $v) => $q->whereLike('e_ncf', '%'.$v.'%'))
                ->when($filtros['usuario'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
                ->when($filtros['accion'] ?? null, fn ($q, $v) => $q->where('action', $v))
                ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
                ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
                ->latest('id')
                ->paginate(30)
                ->withQueryString()
            : null;

        return view('panel.e-invoicing.audit', [
            'filas' => $filas,
            'acciones' => $filas === null ? [] : ElectronicInvoiceAuditLog::query()->distinct()->orderBy('action')->pluck('action')->all(),
            'usuarios' => User::query()->where('company_id', app(CurrentCompany::class)->id())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Los e-CF que otros contribuyentes enviaron a la empresa, con el acuse que se les devolvió. */
    public function received(Request $request): View
    {
        $filas = DbTable::existe('electronic_received_documents')
            ? ElectronicReceivedDocument::query()
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($s) => $s
                    ->whereLike('e_ncf', '%'.$request->input('q').'%')
                    ->orWhereLike('emitter_name', '%'.$request->input('q').'%')
                    ->orWhereLike('emitter_tax_id', '%'.$request->input('q').'%')))
                ->latest('id')
                ->paginate(20)
                ->withQueryString()
            : null;

        $ajustes = DbTable::existe('electronic_invoicing_settings') && ($empresa = app(CurrentCompany::class)->model()) !== null
            ? ElectronicInvoicingSettings::paraEmpresa($empresa)
            : null;

        return view('panel.e-invoicing.received', [
            'filas' => $filas,
            'urls' => $ajustes?->receiverUrls(),
        ]);
    }

    /** Aprobación comercial (ACECF) de la empresa sobre un e-CF recibido: aceptar o rechazar. */
    public function approve(Request $request, ElectronicReceivedDocument $received, CommercialApprovalService $approvals): RedirectResponse
    {
        $datos = $request->validate([
            'decision' => ['required', 'in:aceptar,rechazar'],
            'motivo' => ['required_if:decision,rechazar', 'nullable', 'string', 'max:250'],
        ], ['motivo.required_if' => 'Indica el motivo del rechazo.']);

        try {
            $doc = $approvals->emit($received, $datos['decision'] === 'aceptar', $datos['motivo'] ?? null, $request->user()?->id);
        } catch (Throwable $e) {
            return back()->with('panel_error', 'No se pudo emitir la aprobación comercial: '.$e->getMessage());
        }

        $texto = $doc->approval_status === 1 ? 'aceptado' : 'rechazado';

        return back()->with($doc->approval_error ? 'panel_error' : 'panel_ok', $doc->approval_error
            ? "Comprobante {$texto}, pero hubo problemas al enviarlo: {$doc->approval_error}"
            : "Comprobante {$doc->e_ncf} {$texto} y enviado.");
    }

    /** Descarga el e-CF recibido o el acuse devuelto, comprobando su huella. */
    public function receivedFile(ElectronicReceivedDocument $received, string $kind): Response
    {
        [$ruta, $huella] = match ($kind) {
            'ecf' => [$received->xml_path, $received->xml_sha256],
            'arecf' => [$received->arecf_path, $received->arecf_sha256],
            'acecf' => [$received->acecf_path, $received->acecf_sha256],
            default => abort(404),
        };

        abort_if($ruta === null, 404);
        $bytes = (string) CertificateVault::disk()->get($ruta);
        abort_unless(hash_equals((string) $huella, hash('sha256', $bytes)), 409, 'El archivo no coincide con su huella.');

        return response($bytes, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.EntregaDeArchivo::nombreSeguro(($received->emitter_tax_id ?? 'recibido').($received->e_ncf ?? $received->id)."-{$kind}.xml").'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
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
