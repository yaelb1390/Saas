<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Cache\CompanyCache;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Sales\Models\Sale;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

/**
 * Portal del cliente: pantalla pública, de solo lectura, a la que se llega con un enlace firmado
 * (ver CustomerPortalService). El cliente ve sus facturas, sus compras, sus entregas y su ficha.
 *
 * Vive a nivel de aplicación y no dentro de un módulo porque es una lectura transversal de cuatro
 * dominios (CRM, Ventas, Facturación y Entregas); meterlo en CRM obligaría a ese módulo a conocer
 * los modelos de los otros tres, que es justo lo que prohíben las reglas de arquitectura. Mismo
 * criterio que PanelController.
 *
 * Aquí no hay usuario autenticado, así que el aislamiento por empresa **no puede** salir de la
 * sesión: se deriva del propio cliente que el enlace firmado identifica.
 */
final class CustomerPortalController extends Controller
{
    private const MAX_ROWS = 50;

    public function __construct(private readonly CompanyCache $cache) {}

    public function __invoke(int $customer, CurrentCompany $currentCompany): View
    {
        // Única consulta sin el CompanyScope de todo el flujo, y es deliberada: todavía no hay
        // tenant, precisamente porque es este cliente el que lo determina. Es seguro porque el id
        // llega dentro de una URL firmada: manipularlo invalida la firma y la petición nunca llega
        // hasta aquí. Con route model binding no valdría: si un usuario de otra empresa tuviera
        // sesión abierta, su tenant activo haría que este cliente «no existiera» (404).
        /** @var Customer $record */
        $record = Customer::withoutCompanyScope()->findOrFail($customer);

        $company = Company::query()->with('subscription.plan')->findOrFail($record->company_id);

        // A partir de aquí el tenant queda fijado y el CompanyScope aísla todo lo que sigue.
        $currentCompany->set((int) $record->company_id);

        $this->assertPortalAvailable($company);

        return view('portal.customer', [
            'customer' => $record,
            'company' => $company,
            'invoices' => $this->invoices($company, $record),
            'sales' => $this->sales($company, $record),
            'deliveries' => $this->deliveries($company, $record),
        ]);
    }

    /**
     * El portal se apaga si la empresa no puede operar (suspendida por el operador o con la
     * suscripción vencida). El mensaje es neutro a propósito: el estado de pago de la empresa con
     * la plataforma no es asunto de sus clientes.
     */
    private function assertPortalAvailable(Company $company): void
    {
        $subscription = $company->subscription;
        $blocked = ! $company->is_active || ($subscription !== null && ! $subscription->isUsable());

        abort_if($blocked, 403, 'Este portal no está disponible en este momento.');
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function invoices(Company $company, Customer $customer): Collection
    {
        if (! $company->hasModule('billing')) {
            return collect();
        }

        return $this->cache->remember(
            (int) $customer->company_id,
            "portal:customer:{$customer->id}:invoices",
            fn (): Collection => Invoice::query()
                ->where('customer_id', $customer->id)
                ->latest('issued_at')
                ->limit(self::MAX_ROWS)
                ->get(),
        );
    }

    /**
     * @return Collection<int, Sale>
     */
    private function sales(Company $company, Customer $customer): Collection
    {
        if (! $company->hasModule('sales')) {
            return collect();
        }

        return $this->cache->remember(
            (int) $customer->company_id,
            "portal:customer:{$customer->id}:sales",
            fn (): Collection => Sale::query()
                ->where('customer_id', $customer->id)
                ->with('items')
                ->latest('completed_at')
                ->limit(self::MAX_ROWS)
                ->get(),
        );
    }

    /**
     * @return Collection<int, Delivery>
     */
    private function deliveries(Company $company, Customer $customer): Collection
    {
        if (! $company->hasModule('delivery')) {
            return collect();
        }

        return $this->cache->remember(
            (int) $customer->company_id,
            "portal:customer:{$customer->id}:deliveries",
            fn (): Collection => Delivery::query()
                ->where('customer_id', $customer->id)
                ->latest('id')
                ->limit(self::MAX_ROWS)
                ->get(),
        );
    }
}
