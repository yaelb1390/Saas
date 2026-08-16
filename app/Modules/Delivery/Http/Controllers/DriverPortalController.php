<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Http\Controllers;

use App\Modules\Delivery\Enums\DeliveryOutcomeReason;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Exceptions\DeliveryException;
use App\Modules\Delivery\Http\Requests\CloseDeliveryRequest;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\HR\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * El reparto visto desde el móvil del motorista.
 *
 * Hasta ahora el repartidor no participaba: alguien en el local le preguntaba por teléfono cómo le
 * había ido y tecleaba el resultado. El panel iba siempre por detrás de la calle.
 *
 * Todo lo que se lee aquí se filtra por SU ficha de empleado, y no por lo que venga en la petición.
 * Es la regla que sostiene la pantalla entera: lo que se cobra en la puerta es dinero, y el saldo de
 * un compañero no es asunto suyo.
 */
final class DriverPortalController extends Controller
{
    public function index(Request $request): View
    {
        $empleado = $this->empleadoDe($request);

        if ($empleado === null) {
            return view('portal.deliveries', ['employee' => null, 'deliveries' => collect(), 'enLaCalle' => '0.00']);
        }

        $suyas = Delivery::query()->where('employee_id', $empleado->id);

        return view('portal.deliveries', [
            'employee' => $empleado,

            // Las abiertas, y además las que él cerró HOY: sin eso, cerrar una entrega la hace
            // desaparecer y no hay forma de darse cuenta de que se pulsó el botón equivocado.
            //
            // No se ordenan por estado aquí: la vista ya las separa en dos bloques, y hacerlo también
            // en SQL obligaba a escribir a mano tantos marcadores como estados abiertos hubiera —un
            // estado nuevo y la consulta reventaba—.
            'deliveries' => (clone $suyas)
                ->where(fn ($q) => $q
                    ->whereIn('status', DeliveryStatus::abiertas())
                    ->orWhereDate('delivered_at', today()))
                ->orderBy('id')
                ->get(),

            // Lo que lleva encima. Es lo que le van a preguntar al volver al local, así que lo ve
            // antes de que se lo pregunten.
            'enLaCalle' => (clone $suyas)
                ->whereNotNull('collected_at')->whereNull('settled_at')
                ->sum('amount_to_collect'),
        ]);
    }

    /**
     * Cierra una entrega con lo que pasó.
     *
     * No recibe un estado: recibe el MOTIVO, y el estado sale de él. Ver `DeliveryOutcomeReason`.
     */
    public function close(CloseDeliveryRequest $request, Delivery $delivery, DeliveryService $entregas): RedirectResponse
    {
        $empleado = $this->empleadoDe($request);

        if ($empleado === null) {
            return back()->with('panel_error', DeliveryException::noEresRepartidor()->getMessage());
        }

        // Ocultar no es proteger: la comprobación va aquí y no en la vista. Sin ella bastaría con
        // teclear el código de otra entrega para cerrar la de un compañero.
        if ((int) $delivery->employee_id !== (int) $empleado->id) {
            return back()->with('panel_error', DeliveryException::noEsTuya()->getMessage());
        }

        $motivo = DeliveryOutcomeReason::from($request->string('reason')->toString());

        try {
            $entregas->close(
                delivery: $delivery,
                reason: $motivo,
                note: $request->input('note'),
                cobro: $request->boolean('collected'),
            );
        } catch (DeliveryException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Entrega {$delivery->code}: {$motivo->label()}.");
    }

    /** La ficha de empleado del usuario que ha entrado. Sin ella, no es repartidor de nadie. */
    private function empleadoDe(Request $request): ?Employee
    {
        return Employee::query()->where('user_id', $request->user()?->id)->first();
    }
}
