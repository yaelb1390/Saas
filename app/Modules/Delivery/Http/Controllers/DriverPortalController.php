<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Http\Controllers;

use App\Modules\Core\Support\DbTable;
use App\Modules\Delivery\Enums\DeliveryOutcomeReason;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Exceptions\DeliveryException;
use App\Modules\Delivery\Http\Requests\CloseDeliveryRequest;
use App\Modules\Delivery\Http\Requests\SaveDeliveryLocationRequest;
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
            return view('portal.deliveries', [
                'employee' => null, 'deliveries' => collect(),
                'puedeGuardarUbicacion' => false,
                'resumen' => ['pendientes' => 0, 'enRuta' => 0, 'entregadas' => 0, 'incidencias' => 0],
                'estadoDelRepartidor' => 'fuera',
                'comercio' => null,
            ]);
        }

        $suyas = Delivery::query()->where('employee_id', $empleado->id);

        return view('portal.deliveries', [
            'employee' => $empleado,

            /*
             * ¿Se puede guardar el punto? Solo si la migración está aplicada.
             *
             * En producción se aplican a mano, así que entre que sale este código y alguien migra la
             * columna no existe. Sin esto, el repartidor pulsaría un botón que no guarda nada y le
             * diría que sí: la peor clase de fallo, porque confiaría en un punto que no está.
             */
            'puedeGuardarUbicacion' => DbTable::tieneColumna('deliveries', 'latitude'),

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
                /*
                 * QUE LLEVAR, precargado. Sin esto la vista consultaría la venta, sus líneas y cada
                 * producto una por una: con diez entregas son decenas de consultas en la pantalla que
                 * se abre de pie en la calle, con la peor conexión de todo el sistema.
                 */
                ->with(['sale.items.product', 'customer'])
                ->orderBy('id')
                ->get(),

            /*
             * EL RESUMEN DEL DÍA. Cuenta entregas, NUNCA dinero.
             *
             * El repartidor de esta empresa no cobra: lo hace el comercio. Antes esta pantalla abría
             * con «llevas cobrado y sin entregar en caja», que además de no ser asunto suyo lo hacía
             * responsable de un dinero que nunca debió llevar encima.
             */
            'resumen' => $this->resumenDe($empleado),

            /*
             * Su estado, DEDUCIDO y no declarado. Si fuera un interruptor que él pulsa, se quedaría
             * «disponible» mientras reparte el día que se le olvide tocarlo — y quien asigna en el
             * local le mandaría otra entrega encima.
             */
            'estadoDelRepartidor' => $this->estadoDe($empleado),

            // De qué comercio sale la mercancía. En el reparto de una empresa de logística es lo
            // primero que hay que saber para ir a recogerla.
            'comercio' => $request->user()?->company,
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

    /**
     * Guarda dónde está la puerta, tal como la marcó el repartidor al llegar.
     *
     * La comprobación de que la entrega es SUYA va aquí, igual que en `close()` y por lo mismo:
     * esconder el botón no es proteger nada. Sin esto bastaría teclear el código de otra entrega
     * para escribirle una ubicación a la de un compañero — o peor, a la ficha de su cliente.
     */
    public function ubicacion(SaveDeliveryLocationRequest $request, Delivery $delivery, DeliveryService $entregas): RedirectResponse
    {
        $empleado = $this->empleadoDe($request);

        if ($empleado === null) {
            return back()->with('panel_error', DeliveryException::noEresRepartidor()->getMessage());
        }

        if ((int) $delivery->employee_id !== (int) $empleado->id) {
            return back()->with('panel_error', DeliveryException::noEsTuya()->getMessage());
        }

        $entregas->guardarUbicacion(
            $delivery,
            (string) $request->input('latitude'),
            (string) $request->input('longitude'),
        );

        return back()->with('panel_ok', 'Ubicación guardada. La próxima entrega a este cliente sale con el punto exacto.');
    }

    /**
     * Arranca el viaje: la entrega pasa de asignada a EN RUTA.
     *
     * Sirve para dos cosas a la vez, y por eso vale la pena el toque: en el local saben que ya salió
     * sin tener que llamarlo, y su propio estado pasa a «en entrega», con lo que quien asigna no le
     * echa encima otra parada creyéndolo libre.
     */
    public function iniciar(Request $request, Delivery $delivery, DeliveryService $entregas): RedirectResponse
    {
        $empleado = $this->empleadoDe($request);

        if ($empleado === null) {
            return back()->with('panel_error', DeliveryException::noEresRepartidor()->getMessage());
        }

        // La misma regla que en todo este portal: esconder el botón no es proteger nada.
        if ((int) $delivery->employee_id !== (int) $empleado->id) {
            return back()->with('panel_error', DeliveryException::noEsTuya()->getMessage());
        }

        try {
            $entregas->transition($delivery, DeliveryStatus::InTransit);
        } catch (DeliveryException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Vas en camino con la entrega {$delivery->code}.");
    }

    /**
     * Cuántas lleva hoy de cada cosa. Entregas, no pesos.
     *
     * @return array<string, int>
     */
    private function resumenDe(Employee $empleado): array
    {
        $suyas = Delivery::query()->where('employee_id', $empleado->id);

        return [
            'pendientes' => (clone $suyas)->whereIn('status', [DeliveryStatus::Pending, DeliveryStatus::Assigned])->count(),
            'enRuta' => (clone $suyas)->where('status', DeliveryStatus::InTransit)->count(),
            // Lo cerrado HOY, no de siempre: es un resumen del día, no un historial.
            'entregadas' => (clone $suyas)->where('status', DeliveryStatus::Delivered)->whereDate('delivered_at', today())->count(),
            'incidencias' => (clone $suyas)
                ->whereIn('status', [DeliveryStatus::Failed, DeliveryStatus::Cancelled])
                ->whereDate('updated_at', today())->count(),
        ];
    }

    /** Disponible, en entrega, o fuera de servicio. Se deduce; no hay interruptor que olvidar. */
    private function estadoDe(Employee $empleado): string
    {
        if (! $empleado->is_active) {
            return 'fuera';
        }

        $enRuta = Delivery::query()
            ->where('employee_id', $empleado->id)
            ->where('status', DeliveryStatus::InTransit)
            ->exists();

        return $enRuta ? 'en_entrega' : 'disponible';
    }

    /** La ficha de empleado del usuario que ha entrado. Sin ella, no es repartidor de nadie. */
    private function empleadoDe(Request $request): ?Employee
    {
        return Employee::query()->where('user_id', $request->user()?->id)->first();
    }
}
