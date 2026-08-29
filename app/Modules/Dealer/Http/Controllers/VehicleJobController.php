<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\DbTable;
use App\Modules\Dealer\DTOs\CreateJobData;
use App\Modules\Dealer\Enums\JobStatus;
use App\Modules\Dealer\Enums\VehicleStatus;
use App\Modules\Dealer\Http\Requests\StoreJobRequest;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Models\VehicleJob;
use App\Modules\Dealer\Services\VehicleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * El taller: lo que se le hace a cada unidad antes de venderla.
 *
 * Los importes de esta pantalla SON costos, así que quien no puede ver costos no debería estar aquí:
 * la ruta exige `vehicle_jobs.view`, que solo se le da a quien administra.
 */
final class VehicleJobController extends Controller
{
    public function index(): View
    {
        $faltaMigrar = ! DbTable::existe('vehicle_jobs');

        if ($faltaMigrar) {
            return view('panel.vehicle-jobs', [
                'faltaMigrar' => true,
                'trabajos' => collect(),
                'vehiculos' => collect(),
                'gastadoEnTotal' => '0.00',
            ]);
        }

        $trabajos = VehicleJob::query()
            ->with('vehicle:id,code,make,model,year')
            ->when(request('estado'), fn ($q, $e) => $q->where('status', $e))
            ->when(request('vehiculo'), fn ($q, $id) => $q->where('vehicle_id', $id))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('panel.vehicle-jobs', [
            'faltaMigrar' => false,
            'trabajos' => $trabajos,
            // Las vendidas no se ofrecen: anotarle un gasto a un carro que ya salió del patio suele
            // ser que se eligió mal en la lista.
            'vehiculos' => Vehicle::query()
                ->whereIn('status', [VehicleStatus::Available->value, VehicleStatus::Reserved->value])
                ->orderBy('make')->orderBy('model')
                ->get(['id', 'code', 'make', 'model', 'year']),
            'estados' => JobStatus::cases(),
            'gastadoEnTotal' => number_format((float) VehicleJob::query()->sum('cost'), 2),
        ]);
    }

    public function store(StoreJobRequest $request, VehicleService $vehiculos): RedirectResponse
    {
        $d = $request->validated();

        $vehiculos->addJob(new CreateJobData(
            vehicleId: (int) $d['vehicle_id'],
            description: (string) $d['description'],
            cost: (string) ($d['cost'] ?? '0'),
            performedBy: $d['performed_by'] ?? null,
            performedAt: $d['performed_at'] ?? null,
            done: (bool) ($d['done'] ?? false),
            notes: $d['notes'] ?? null,
        ));

        return back()->with('panel_success', 'Trabajo anotado; ya cuenta en el costo de la unidad.');
    }

    /** Marca el trabajo como hecho. Es un botón, no un formulario: es lo único que cambia. */
    public function complete(VehicleJob $job): RedirectResponse
    {
        Gate::authorize('vehicle_jobs.manage');

        $job->status = JobStatus::Done;
        $job->performed_at ??= now()->toDateString();
        $job->save();

        return back()->with('panel_success', 'Trabajo marcado como hecho.');
    }
}
