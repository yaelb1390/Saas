<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Modules\CRM\DTOs\CreateCustomerData;
use App\Modules\CRM\Exceptions\CustomerPortalException;
use App\Modules\CRM\Http\Requests\StoreCustomerRequest;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Services\CrmService;
use App\Modules\CRM\Services\CustomerPortalService;
use Illuminate\Http\RedirectResponse;
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
            address: $data['address'] ?? null,
        ));

        return back()->with('panel_ok', 'Cliente creado correctamente.');
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
