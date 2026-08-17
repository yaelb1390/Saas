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
use App\Modules\WhatsApp\Models\WaConversation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

/**
 * La ficha del cliente vista desde dentro: quién es, qué compró, qué debe y qué le falta por recibir.
 *
 * Vive a nivel de aplicación y NO dentro del módulo CRM porque es una lectura transversal de cuatro
 * dominios (CRM, Ventas, Facturación y Entregas); meterla en CRM obligaría a ese módulo a conocer los
 * modelos de los otros tres, que es justo lo que prohíben las reglas de arquitectura. Mismo criterio
 * y mismo motivo que CustomerPortalController, que existe por esto.
 *
 * Hasta ahora el PORTAL PÚBLICO enseñaba más que esta pantalla: el cliente veía sus facturas, sus
 * compras y sus entregas, y el dueño del negocio no veía ninguna de las tres. Los datos ya estaban
 * enlazados —`customer_id` viaja desde el punto de venta, la facturación y las entregas—; solo
 * faltaba pintarlos.
 *
 * Aquí sí hay usuario autenticado, así que el aislamiento sale del route model binding: un cliente de
 * otra empresa simplemente no existe y devuelve 404.
 */
final class CustomerProfileController extends Controller
{
    /** Lo que cabe en una pantalla sin volverse un listado. Para todo lo demás está su módulo. */
    private const MAX_ROWS = 15;

    public function __construct(private readonly CompanyCache $cache) {}

    public function __invoke(Customer $customer, CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->model();

        abort_if($company === null, 403);

        $customer->load(['documents', 'loans']);

        $ventas = $this->ventas($company, $customer);
        $facturas = $this->facturas($company, $customer);

        return view('panel.customer', [
            'customer' => $customer,
            'ventas' => $ventas,
            'facturas' => $facturas,
            'entregas' => $this->entregas($company, $customer),
            'conversacion' => $this->conversacion($company, $customer),
            'comprado' => $this->comprado($company, $customer),
            'debe' => $this->debe($company, $customer),
        ]);
    }

    /**
     * Archivar: deja de aparecer al vender y en el listado, y se puede deshacer.
     *
     * Es lo que el diálogo de la pantalla ya prometía cuando en realidad hacía un borrado lógico.
     * Ahora coincide lo que dice con lo que hace, y de paso le da trabajo real a `is_active`, que ya
     * decide en media aplicación y que nadie podía escribir.
     */
    public function toggle(Customer $customer): RedirectResponse
    {
        $customer->update(['is_active' => ! $customer->is_active]);

        return back()->with('panel_ok', $customer->is_active
            ? "{$customer->name} vuelve a estar activo."
            : "{$customer->name} queda archivado: deja de aparecer al vender.");
    }

    /**
     * Eliminar de verdad, y SOLO si no queda rastro suyo en ninguna parte.
     *
     * Antes esto era un `delete()` a pelo. Como el cliente se borra en blando, cada `belongsTo` de
     * Ventas, Facturación, Entregas, WhatsApp y Préstamos pasaba a devolver null con la clave ajena
     * apuntando a una fila viva: la ficha de un préstamo de ese cliente reventaba.
     *
     * Así que eliminar queda para lo único que lo merece —la ficha creada por error hace cinco
     * minutos— y todo lo demás se archiva, que es reversible y no rompe nada.
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        $ataduras = $this->ataduras($customer);

        if ($ataduras !== []) {
            return back()->with('panel_error', sprintf(
                'No se puede eliminar a «%s»: tiene %s. Puedes archivarlo y deja de aparecer al vender.',
                $customer->name,
                $this->enumerar($ataduras),
            ));
        }

        $customer->delete();

        return redirect()->route('panel.customers')
            ->with('panel_ok', "Cliente «{$customer->name}» eliminado.");
    }

    /**
     * Todo lo que ata a un cliente, contado.
     *
     * Va aquí y no en CRM por lo mismo que el resto: mirar ventas y facturas desde dentro del módulo
     * lo obligaría a conocerlas.
     *
     * @return array<int, string>
     */
    private function ataduras(Customer $customer): array
    {
        $company = $customer->company;

        $conteos = [
            ['sales', Sale::query()->where('customer_id', $customer->id)->count(), 'venta', 'ventas'],
            ['billing', Invoice::query()->where('customer_id', $customer->id)->count(), 'factura', 'facturas'],
            ['delivery', Delivery::query()->where('customer_id', $customer->id)->count(), 'entrega', 'entregas'],
            [null, $customer->loans()->count(), 'préstamo', 'préstamos'],
            [null, $customer->opportunities()->count(), 'oportunidad', 'oportunidades'],
        ];

        $ataduras = [];

        foreach ($conteos as [$modulo, $cuantos, $singular, $plural]) {
            // Un módulo que la empresa no tiene contratado no puede tener filas suyas, pero
            // consultarlo igualmente sería trabajo tirado.
            if ($modulo !== null && $company !== null && ! $company->hasModule($modulo)) {
                continue;
            }

            if ($cuantos > 0) {
                $ataduras[] = $cuantos.' '.($cuantos === 1 ? $singular : $plural);
            }
        }

        return $ataduras;
    }

    /** «3 ventas, 2 facturas y un préstamo». */
    private function enumerar(array $partes): string
    {
        if (count($partes) === 1) {
            return $partes[0];
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes).' y '.$ultima;
    }

    /**
     * Cuánto le ha comprado en total, no solo en las últimas quince.
     *
     * Se pregunta a la base en vez de sumar la lista de arriba: esa está acotada a quince filas y
     * sumarla daría una cifra que se queda corta justo con los clientes que más compran.
     */
    private function comprado(Company $company, Customer $customer): string
    {
        if (! $company->hasModule('sales')) {
            return '0';
        }

        return $this->cache->remember(
            (int) $customer->company_id,
            "crm:customer:{$customer->id}:comprado",
            fn (): string => (string) Sale::query()->where('customer_id', $customer->id)->sum('total'),
        );
    }

    /**
     * Lo que el cliente debe: lo fiado.
     *
     * Es la diferencia entre lo que suman sus ventas y lo que ha pagado. No sale de las facturas
     * —esas no llevan saldo— sino de las propias ventas, que es donde vive el fiado de un colmado.
     */
    private function debe(Company $company, Customer $customer): string
    {
        if (! $company->hasModule('sales')) {
            return '0';
        }

        return $this->cache->remember(
            (int) $customer->company_id,
            "crm:customer:{$customer->id}:debe",
            function () use ($customer): string {
                $saldo = Sale::query()
                    ->where('customer_id', $customer->id)
                    ->selectRaw('COALESCE(SUM(total - paid), 0) AS saldo')
                    ->value('saldo');

                // Nunca en negativo: quien pagó de más no «debe» una cifra con signo menos, y un
                // cambio mal apuntado no debería restar de la deuda de otra venta.
                return bccomp((string) $saldo, '0', 2) > 0 ? (string) $saldo : '0';
            },
        );
    }

    /**
     * @return Collection<int, Sale>
     */
    private function ventas(Company $company, Customer $customer): Collection
    {
        if (! $company->hasModule('sales')) {
            return collect();
        }

        // Clave propia, distinta de la del portal: aquí se enseñan menos filas y se calculan
        // agregados. Compartiendo clave, cambiar el límite de una pantalla degradaría la otra.
        return $this->cache->remember(
            (int) $customer->company_id,
            "crm:customer:{$customer->id}:ventas",
            fn (): Collection => Sale::query()
                ->where('customer_id', $customer->id)
                ->latest('completed_at')
                ->limit(self::MAX_ROWS)
                ->get(),
        );
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function facturas(Company $company, Customer $customer): Collection
    {
        if (! $company->hasModule('billing')) {
            return collect();
        }

        return $this->cache->remember(
            (int) $customer->company_id,
            "crm:customer:{$customer->id}:facturas",
            fn (): Collection => Invoice::query()
                ->where('customer_id', $customer->id)
                ->latest('issued_at')
                ->limit(self::MAX_ROWS)
                ->get(),
        );
    }

    /**
     * @return Collection<int, Delivery>
     */
    private function entregas(Company $company, Customer $customer): Collection
    {
        if (! $company->hasModule('delivery')) {
            return collect();
        }

        return $this->cache->remember(
            (int) $customer->company_id,
            "crm:customer:{$customer->id}:entregas",
            fn (): Collection => Delivery::query()
                ->where('customer_id', $customer->id)
                ->latest('id')
                ->limit(self::MAX_ROWS)
                ->get(),
        );
    }

    /**
     * Su conversación de WhatsApp, si la hay.
     *
     * No se cachea: el buzón cambia con cada mensaje y una lista de mensajes vieja es peor que una
     * consulta más. Solo se necesita el identificador para poder enlazar.
     */
    private function conversacion(Company $company, Customer $customer): ?WaConversation
    {
        if (! $company->hasModule('whatsapp')) {
            return null;
        }

        return WaConversation::query()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->first();
    }
}
