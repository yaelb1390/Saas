<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Http\Controllers;

use App\Modules\CRM\Models\Customer;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Http\Requests\StoreDeliveryRequest;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Sale;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Acciones del reparto.
 *
 * Es literalmente el módulo entero: hasta ahora la única ruta de Entregas era la pantalla de solo
 * lectura, así que `DeliveryService` tenía tres métodos que ningún camino del código podía alcanzar.
 */
final class DeliveryController extends Controller
{
    public function store(StoreDeliveryRequest $request, DeliveryService $entregas): RedirectResponse
    {
        $datos = $request->validated();

        try {
            $entrega = $entregas->create(
                address: $datos['address'],
                customerName: $datos['customer_name'] ?? null,
                phone: $datos['phone'] ?? null,
                sale: isset($datos['sale_id']) ? Sale::find($datos['sale_id']) : null,
                customer: isset($datos['customer_id']) ? Customer::find($datos['customer_id']) : null,
                amountToCollect: $datos['amount_to_collect'] ?? null,
                notes: $datos['notes'] ?? null,
            );

            // Asignar en el mismo paso es lo normal: quien apunta el pedido ya sabe quién lo lleva.
            if (! empty($datos['employee_id'])) {
                $entregas->assign($entrega, Employee::findOrFail($datos['employee_id']));
            }
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Entrega {$entrega->code} registrada.");
    }

    public function assign(Request $request, Delivery $delivery, DeliveryService $entregas): RedirectResponse
    {
        $empleado = Employee::find($request->integer('employee_id'));

        if ($empleado === null) {
            return back()->with('panel_error', 'Elige un repartidor de la lista.');
        }

        try {
            $entregas->assign($delivery, $empleado);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "{$empleado->name} lleva la entrega {$delivery->code}.");
    }

    public function transition(Request $request, Delivery $delivery, DeliveryService $entregas): RedirectResponse
    {
        $destino = DeliveryStatus::tryFrom((string) $request->input('status'));

        if ($destino === null) {
            return back()->with('panel_error', 'Ese estado no existe.');
        }

        try {
            $entregas->transition($delivery, $destino);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Entrega {$delivery->code}: {$destino->label()}.");
    }

    /** El motorista entregó y cobró: ese dinero pasa a estar en su mochila. */
    public function collect(Delivery $delivery, DeliveryService $entregas): RedirectResponse
    {
        try {
            $entregas->markCollected($delivery);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', sprintf(
            'Entrega %s cobrada: %s pendientes de liquidar.',
            $delivery->code,
            money($delivery->amount_to_collect),
        ));
    }

    /** El motorista trajo el dinero a caja. */
    public function settle(Employee $employee, DeliveryService $entregas): RedirectResponse
    {
        try {
            $resultado = $entregas->settle($employee);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', sprintf(
            '%s liquidó %d %s por %s.',
            $employee->name,
            $resultado['entregas'],
            $resultado['entregas'] === 1 ? 'entrega' : 'entregas',
            money($resultado['total']),
        ));
    }
}
