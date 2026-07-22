<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CustomerResource;
use App\Modules\CRM\DTOs\CreateCustomerData;
use App\Modules\CRM\Http\Requests\StoreCustomerRequest;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Services\CrmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Clientes vía API v1. Aislado por la empresa del token (CompanyScope).
 */
final class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $customers = Customer::query()
            ->when($request->query('q'), fn ($query, $q) => $query->where(
                fn ($sub) => $sub->whereLike('name', "%{$q}%")->orWhereLike('phone', "%{$q}%")
            ))
            ->latest()
            ->paginate((int) $request->integer('per_page', 25));

        return CustomerResource::collection($customers);
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer);
    }

    public function store(StoreCustomerRequest $request, CrmService $crm): JsonResponse
    {
        $data = $request->validated();

        $customer = $crm->createCustomer(new CreateCustomerData(
            name: $data['name'],
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            taxId: $data['tax_id'] ?? null,
            address: $data['address'] ?? null,
        ));

        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function update(StoreCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validated());

        return new CustomerResource($customer->refresh());
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return response()->json(null, 204);
    }
}
