<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Modules\CRM\DTOs\CreateCustomerData;
use App\Modules\CRM\Exceptions\CustomerPortalException;
use App\Modules\CRM\Http\Requests\StoreCustomerDocumentRequest;
use App\Modules\CRM\Http\Requests\StoreCustomerRequest;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Models\CustomerDocument;
use App\Modules\CRM\Services\CrmService;
use App\Modules\CRM\Services\CustomerPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class CustomerController extends Controller
{
    public function store(StoreCustomerRequest $request, CrmService $crm): RedirectResponse
    {
        $data = $request->validated();

        $crm->createCustomer(new CreateCustomerData(
            name: $data['name'],
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            taxId: $data['tax_id'] ?? null,
            cedula: $data['cedula'] ?? null,
            address: $data['address'] ?? null,
        ));

        return back()->with('panel_ok', 'Cliente creado correctamente.');
    }

    /**
     * Perfil del cliente: datos, cédula, documentos y sus préstamos. El route model binding lo
     * resuelve ya aislado por la empresa activa (un id ajeno devuelve 404).
     */
    public function show(Customer $customer): View
    {
        $customer->load(['documents', 'loans']);

        return view('panel.customer', ['customer' => $customer]);
    }

    /**
     * Sube un documento (foto de cédula, contrato...) al perfil. El contenido se guarda en la base
     * en base64 para que persista en serverless sin disco externo.
     */
    public function storeDocument(StoreCustomerDocumentRequest $request, Customer $customer): RedirectResponse
    {
        $file = $request->file('file');

        $customer->documents()->create([
            'company_id' => $customer->company_id,
            'name' => $request->string('name')->trim()->value() ?: $file->getClientOriginalName(),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'content' => base64_encode((string) file_get_contents($file->getRealPath())),
            'user_id' => auth()->id(),
        ]);

        return back()->with('panel_ok', 'Documento subido.');
    }

    /**
     * Devuelve el documento para verlo o descargarlo. Se decodifica el base64 guardado en la base.
     */
    public function showDocument(Customer $customer, CustomerDocument $document): Response
    {
        abort_if($document->customer_id !== $customer->id, 404);

        return response(base64_decode($document->content, true) ?: '', 200, [
            'Content-Type' => $document->mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($document->name).'"',
        ]);
    }

    public function destroyDocument(Customer $customer, CustomerDocument $document): RedirectResponse
    {
        abort_if($document->customer_id !== $customer->id, 404);

        $document->delete();

        return back()->with('panel_ok', 'Documento eliminado.');
    }

    public function update(StoreCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        return back()->with('panel_ok', 'Cliente actualizado.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();

        return back()->with('panel_ok', 'Cliente eliminado.');
    }

    /**
     * Envía al cliente el enlace a su portal por WhatsApp. El route model binding ya resuelve el
     * cliente aislado por la empresa activa: un id de otra empresa devuelve 404.
     */
    public function sendPortalLink(Customer $customer, CustomerPortalService $portal): RedirectResponse
    {
        try {
            $portal->sendLink($customer);
        } catch (CustomerPortalException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Enlace del portal enviado a {$customer->name} por WhatsApp.");
    }
}
