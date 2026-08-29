<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\BusquedaTexto;
use App\Modules\Core\Support\DbTable;
use App\Modules\Dealer\DTOs\CreateDealData;
use App\Modules\Dealer\Enums\DealStatus;
use App\Modules\Dealer\Enums\VehicleStatus;
use App\Modules\Dealer\Exceptions\DealerException;
use App\Modules\Dealer\Http\Requests\RegisterDealPaymentRequest;
use App\Modules\Dealer\Http\Requests\StoreDealRequest;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Models\VehicleDeal;
use App\Modules\Dealer\Services\VehicleDealService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Apartar, vender y cobrar. */
final class VehicleDealController extends Controller
{
    public function index(): View
    {
        $faltaMigrar = ! DbTable::existe('vehicle_deals');

        if ($faltaMigrar) {
            return view('panel.vehicle-deals', [
                'faltaMigrar' => true,
                'tratos' => collect(),
                'disponibles' => collect(),
                'estados' => DealStatus::cases(),
            ]);
        }

        $tratos = VehicleDeal::query()
            ->with(['vehicle:id,code,make,model,year', 'customer:id,name'])
            ->withCount(['installments as cuotas_vencidas' => fn ($q) => $q
                ->where('status', '!=', 'paid')
                ->whereDate('due_date', '<', now()->toDateString())])
            ->when(request('estado'), fn ($q, $e) => $q->where('status', $e))
            ->when(request('q'), fn ($q, $texto) => BusquedaTexto::enCualquiera(
                $q, ['code', 'customer_name'], (string) $texto,
            ))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('panel.vehicle-deals', [
            'faltaMigrar' => false,
            'tratos' => $tratos,
            // Solo las que se pueden vender: ofrecer una ya apartada solo sirve para que alguien la
            // elija y se lleve un error.
            'disponibles' => Vehicle::query()
                ->where('status', VehicleStatus::Available->value)
                ->orderBy('make')->orderBy('model')
                ->get(['id', 'code', 'make', 'model', 'year', 'asking_price']),
            'estados' => DealStatus::cases(),
        ]);
    }

    public function store(StoreDealRequest $request, VehicleDealService $tratos): RedirectResponse
    {
        $d = $request->validated();

        try {
            $trato = $tratos->open(new CreateDealData(
                vehicleId: (int) $d['vehicle_id'],
                customerId: (int) $d['customer_id'],
                agreedPrice: (string) $d['agreed_price'],
                downPayment: (string) ($d['down_payment'] ?? '0'),
                tradeInVehicleId: isset($d['trade_in_vehicle_id']) ? (int) $d['trade_in_vehicle_id'] : null,
                tradeInValue: (string) ($d['trade_in_value'] ?? '0'),
                financing: (string) ($d['financing'] ?? 'none'),
                interestRate: (string) ($d['interest_rate'] ?? '0'),
                interestAmount: $d['interest_amount'] ?? null,
                frequency: $d['frequency'] ?? null,
                installmentsCount: (int) ($d['installments_count'] ?? 0),
                startDate: $d['start_date'] ?? null,
                notes: $d['notes'] ?? null,
                close: (bool) ($d['close'] ?? false),
            ));
        } catch (DealerException $e) {
            // El motivo de negocio se le enseña tal cual: está escrito para quien atiende.
            return back()->withInput()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_success', "Trato {$trato->code} registrado.");
    }

    public function close(VehicleDeal $deal, VehicleDealService $tratos): RedirectResponse
    {
        try {
            $tratos->close($deal);
        } catch (DealerException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_success', "Trato {$deal->code} cerrado.");
    }

    public function cancel(VehicleDeal $deal, VehicleDealService $tratos): RedirectResponse
    {
        $tratos->cancel($deal);

        return back()->with('panel_success', "Trato {$deal->code} dado de baja; la unidad vuelve al patio.");
    }

    public function payment(RegisterDealPaymentRequest $request, VehicleDeal $deal, VehicleDealService $tratos): RedirectResponse
    {
        try {
            $tratos->registerPayment($deal, (string) $request->validated('amount'), [
                'method' => $request->validated('method'),
                'reference' => $request->validated('reference'),
            ]);
        } catch (DealerException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_success', 'Abono registrado.');
    }
}
