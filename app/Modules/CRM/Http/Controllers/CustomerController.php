<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Modules\Core\Support\EntregaDeArchivo;
use App\Modules\CRM\DTOs\CreateCustomerData;
use App\Modules\CRM\Exceptions\CustomerPortalException;
use App\Modules\CRM\Http\Requests\StoreCustomerDocumentRequest;
use App\Modules\CRM\Http\Requests\StoreCustomerRequest;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Models\CustomerDocument;
use App\Modules\CRM\Services\CrmService;
use App\Modules\CRM\Services\CustomerPortalService;
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
            // El DTO lo aceptaba desde el primer día y aquí no se pasaba: las notas se perdían al
            // crear sin que nadie viera un error.
            notes: $data['notes'] ?? null,
        ));

        return back()->with('panel_ok', 'Cliente creado correctamente.');
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
            // `nombreSeguro` y no `addslashes`: aquél escapa la comilla pero deja pasar el salto de
            // línea, que es justo con lo que se parte una cabecera en dos y se cuela otra inyectada.
            // El nombre lo escribe quien sube el fichero, así que no es de fiar.
            'Content-Disposition' => 'inline; filename="'.EntregaDeArchivo::nombreSeguro((string) $document->name).'"',
            // Papeles con datos personales: ni se guardan ni pasan por intermediarios.
            'Cache-Control' => 'private, max-age=0, no-store',
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
