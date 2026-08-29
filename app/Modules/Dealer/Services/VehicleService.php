<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Services;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Dealer\DTOs\CreateJobData;
use App\Modules\Dealer\DTOs\CreateVehicleData;
use App\Modules\Dealer\Enums\JobStatus;
use App\Modules\Dealer\Enums\VehicleStatus;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Models\VehicleJob;
use App\Modules\Dealer\Support\VehicleImageStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El patio: dar de alta unidades y anotar lo que se les hace.
 *
 * Lo que NO está aquí es vender: eso vive en `VehicleDealService`, porque toca dinero, cliente y
 * concurrencia, y mezclarlo haría un servicio que hace dos cosas distintas.
 */
final class VehicleService
{
    private const SCALE = 2;

    public function create(CreateVehicleData $data): Vehicle
    {
        return DB::transaction(function () use ($data): Vehicle {
            $companyId = app(CurrentCompany::class)->id() ?? 0;

            $vehicle = new Vehicle([
                'company_id' => $companyId,
                'branch_id' => $data->branchId,
                'code' => $this->nextCode($companyId),
                'vin' => $this->limpiarVin($data->vin),
                'make' => trim($data->make),
                'model' => trim($data->model),
                'year' => $data->year,
                'trim' => $data->trim,
                'color' => $data->color,
                'mileage' => $data->mileage,
                'fuel' => $data->fuel,
                'transmission' => $data->transmission,
                'plate' => $data->plate,
                'purchase_cost' => $this->normalize($data->purchaseCost),
                'asking_price' => $this->normalize($data->askingPrice),
                'status' => VehicleStatus::Available,
                'acquired_at' => $data->acquiredAt,
                'notes' => $data->notes,
                'user_id' => auth()->id(),
            ]);
            $vehicle->save();

            // Después de guardar, no antes: la ruta del fichero se cuelga de la unidad ya creada.
            if ($data->photo !== null) {
                app(VehicleImageStore::class)->store($vehicle, $data->photo);
            }

            return $vehicle;
        });
    }

    /** Anota un trabajo de preparación. Su costo entra en el costo real de la unidad. */
    public function addJob(CreateJobData $data): VehicleJob
    {
        $companyId = app(CurrentCompany::class)->id() ?? 0;

        // El vehículo se busca DENTRO del ámbito de empresa: así, si alguien manda el id de una
        // unidad ajena, no existe y no hay nada que decidir.
        $vehicle = Vehicle::query()->findOrFail($data->vehicleId);

        return VehicleJob::create([
            'company_id' => $companyId,
            'vehicle_id' => $vehicle->id,
            'description' => trim($data->description),
            'cost' => $this->normalize($data->cost),
            'performed_by' => $data->performedBy,
            'status' => $data->done ? JobStatus::Done : JobStatus::Pending,
            'performed_at' => $data->performedAt,
            'notes' => $data->notes,
            'user_id' => auth()->id(),
        ]);
    }

    /**
     * Las unidades para la rejilla, con sus gastos ya sumados.
     *
     * `withSum` y no un `sum()` por fila: con doscientas unidades en pantalla, preguntar los gastos
     * de una en una serían doscientas consultas, que es como esta pantalla se vuelve la más lenta
     * del panel.
     *
     * @return Builder<Vehicle>
     */
    public function paraLaRejilla(): Builder
    {
        return Vehicle::query()
            ->withSum('jobs as jobs_sum_cost', 'cost')
            ->with('branch:id,name')
            ->orderByDesc('id');
    }

    /**
     * El chasis, sin espacios y en mayúsculas.
     *
     * Se teclea a mano copiando de una chapa, así que llega con espacios y en cualquier caja. Sin
     * normalizar, buscar «1hgcm82633a» no encontraría el que se guardó como «1HGCM82633A».
     */
    private function limpiarVin(?string $vin): ?string
    {
        $limpio = mb_strtoupper(trim((string) $vin));

        return $limpio === '' ? null : $limpio;
    }

    /**
     * El siguiente código de la empresa.
     *
     * `withTrashed()` porque la unidad se archiva, no se destruye: sin contar las archivadas, el
     * siguiente código repetiría uno ya usado y chocaría contra el índice único de
     * `(company_id, code)` justo al registrar un carro.
     */
    private function nextCode(int $companyId): string
    {
        $count = Vehicle::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->count();

        return 'VH-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    private function normalize(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }
}
