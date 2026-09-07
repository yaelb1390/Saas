<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Support\EstadoDelRepartidor;
use App\Modules\HR\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Portal del empleado: el usuario autenticado ve su propia ficha y su historial de asistencia,
 * ya aislado por la empresa activa.
 */
final class EmployeePortalController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $employee = Employee::query()
            ->where('user_id', $user->id)
            ->with(['attendances' => fn ($query) => $query->latest('clock_in')->limit(10)])
            ->first();

        /*
         * LO DEL REPARTO, solo si esta persona reparte.
         *
         * Se pregunta por el permiso y no por el puesto escrito en la ficha: «Motorista», «Delivery» y
         * «repartidor» son la misma cosa escrita de tres maneras, y comparar textos acaba dejando
         * fuera a alguien por una tilde.
         *
         * NINGUNA CIFRA DE DINERO. El repartidor no cobra, y su propio salario —que la ficha sí
         * guarda— tampoco se enseña aquí: esta pantalla la abre él, en su móvil, en la calle.
         */
        $reparte = $employee !== null && $user?->can('delivery.own');

        return view('portal.employee', [
            'user' => $user,
            'employee' => $employee,
            'reparte' => $reparte,
            'estadoDeReparto' => $reparte ? EstadoDelRepartidor::de($employee) : null,
            'entregasHechas' => $reparte
                ? Delivery::query()
                    ->where('employee_id', $employee->id)
                    ->where('status', DeliveryStatus::Delivered)
                    ->count()
                : 0,
        ]);
    }
}
