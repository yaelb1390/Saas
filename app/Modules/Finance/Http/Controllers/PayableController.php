<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Enums\PayableStatus;
use App\Modules\Finance\Http\Requests\PayPayableRequest;
use App\Modules\Finance\Http\Requests\StorePayableRequest;
use App\Modules\Finance\Http\Requests\UpdatePayableRequest;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Services\PayableService;
use App\Modules\Purchasing\Models\Supplier;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Cuentas por pagar desde el panel: darlas de alta a mano y registrar abonos. Delgado: valida,
 * delega en PayableService y traduce las reglas de dominio a mensajes. Todo el movimiento de saldo
 * vive en el servicio.
 */
final class PayableController extends Controller
{
    public function index(): View
    {
        $cuentas = Payable::query()
            ->with(['supplier', 'purchaseOrder'])
            ->withCount('payments')
            ->when(request('estado'), fn ($q) => $q->where('status', request('estado')))
            ->when(request()->boolean('vencidas'), fn ($q) => $q
                ->whereNotNull('due_date')
                ->where('due_date', '<', now()->toDateString())
                ->where('status', '!=', PayableStatus::Paid->value))
            ->when(request('q'), fn ($q, $texto) => $q->where(fn ($sub) => $sub
                ->whereLike('code', "%{$texto}%")
                ->orWhereLike('supplier_name', "%{$texto}%")))
            ->orderByRaw("(status = 'paid') asc")
            ->orderBy('due_date')
            ->paginate(20)
            ->withQueryString();

        return view('panel.payables', [
            'cuentas' => $cuentas,
            'statuses' => PayableStatus::cases(),
            'proveedores' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Payable $payable): View
    {
        return view('panel.payable-show', [
            'cuenta' => $payable->load(['supplier', 'purchaseOrder', 'payments.account', 'payments.user']),
            'accounts' => Account::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StorePayableRequest $request, PayableService $payables): RedirectResponse
    {
        try {
            $cuenta = $payables->crear($request->validated());
        } catch (DomainException $e) {
            return back()->withInput()->with('panel_error', $e->getMessage());
        }

        return redirect()
            ->route('panel.payables.show', $cuenta)
            ->with('panel_ok', "Cuenta por pagar {$cuenta->code} creada.");
    }

    public function pay(PayPayableRequest $request, Payable $payable, PayableService $payables): RedirectResponse
    {
        $datos = $request->validated();

        try {
            $payables->registerPayment($payable, (string) $datos['amount'], $datos);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Pago registrado.');
    }

    public function update(UpdatePayableRequest $request, Payable $payable, PayableService $payables): RedirectResponse
    {
        try {
            $payables->actualizar($payable, $request->validated());
        } catch (DomainException $e) {
            return back()->withInput()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Cuenta por pagar actualizada.');
    }

    public function destroy(Payable $payable, PayableService $payables): RedirectResponse
    {
        try {
            $payables->eliminar($payable);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return redirect()->route('panel.payables')->with('panel_ok', 'Cuenta por pagar eliminada.');
    }
}
