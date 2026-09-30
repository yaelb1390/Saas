<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\CRM\Models\Customer;
use App\Modules\Finance\Enums\ReceivableStatus;
use App\Modules\Finance\Http\Requests\PayReceivableRequest;
use App\Modules\Finance\Http\Requests\StoreReceivableRequest;
use App\Modules\Finance\Http\Requests\UpdateReceivableRequest;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\ReceivableService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Cuentas por cobrar desde el panel: darlas de alta a mano y registrar abonos. Delgado: valida,
 * delega en ReceivableService y traduce las reglas de dominio a mensajes. Todo el movimiento de
 * saldo vive en el servicio.
 */
final class ReceivableController extends Controller
{
    public function index(): View
    {
        $cuentas = Receivable::query()
            ->with(['customer', 'sale'])
            ->withCount('payments')
            ->when(request('estado'), fn ($q) => $q->where('status', request('estado')))
            ->when(request()->boolean('vencidas'), fn ($q) => $q
                ->whereNotNull('due_date')
                ->where('due_date', '<', now()->toDateString())
                ->where('status', '!=', ReceivableStatus::Paid->value))
            ->when(request('q'), fn ($q, $texto) => $q->where(fn ($sub) => $sub
                ->whereLike('code', "%{$texto}%")
                ->orWhereLike('customer_name', "%{$texto}%")))
            ->orderByRaw("(status = 'paid') asc")
            ->orderBy('due_date')
            ->paginate(20)
            ->withQueryString();

        return view('panel.receivables', [
            'cuentas' => $cuentas,
            'statuses' => ReceivableStatus::cases(),
            'clientes' => Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Receivable $receivable): View
    {
        return view('panel.receivable-show', [
            'cuenta' => $receivable->load(['customer', 'sale', 'payments.account', 'payments.user']),
            'accounts' => Account::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreReceivableRequest $request, ReceivableService $receivables): RedirectResponse
    {
        try {
            $cuenta = $receivables->crear($request->validated());
        } catch (DomainException $e) {
            return back()->withInput()->with('panel_error', $e->getMessage());
        }

        return redirect()
            ->route('panel.receivables.show', $cuenta)
            ->with('panel_ok', "Cuenta por cobrar {$cuenta->code} creada.");
    }

    public function pay(PayReceivableRequest $request, Receivable $receivable, ReceivableService $receivables): RedirectResponse
    {
        $datos = $request->validated();

        try {
            $receivables->registerPayment($receivable, (string) $datos['amount'], $datos);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Abono registrado.');
    }

    public function update(UpdateReceivableRequest $request, Receivable $receivable, ReceivableService $receivables): RedirectResponse
    {
        try {
            $receivables->actualizar($receivable, $request->validated());
        } catch (DomainException $e) {
            return back()->withInput()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Cuenta por cobrar actualizada.');
    }

    public function destroy(Receivable $receivable, ReceivableService $receivables): RedirectResponse
    {
        try {
            $receivables->eliminar($receivable);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return redirect()->route('panel.receivables')->with('panel_ok', 'Cuenta por cobrar eliminada.');
    }
}
