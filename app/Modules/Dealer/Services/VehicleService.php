<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Services;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Dealer\DTOs\CreateJobData;
use App\Modules\Dealer\DTOs\CreateVehicleData;
use App\Modules\Dealer\Enums\ExpenseType;
use App\Modules\Dealer\Enums\JobStatus;
use App\Modules\Dealer\Enums\VehicleStatus;
use App\Modules\Dealer\Events\VehicleExpenseRecorded;
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

    /**
     * Corrige los datos de una unidad.
     *
     * Faltaba, y era el hueco más grave del módulo: hasta ahora un chasis mal tecleado o un precio
     * equivocado no se podían arreglar. El código NO se toca —es el identificador con el que el
     * dealer se refiere a la unidad— y el estado tampoco: ese lo mueven los tratos, no un formulario.
     *
     * @param  array<string, mixed>  $datos
     */
    public function update(Vehicle $vehicle, array $datos): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $datos): Vehicle {
            $vehicle->fill([
                'branch_id' => $datos['branch_id'] ?? null,
                'vin' => $this->limpiarVin($datos['vin'] ?? null),
                'make' => trim((string) $datos['make']),
                'model' => trim((string) $datos['model']),
                'year' => $datos['year'] ?? null,
                'trim' => $datos['trim'] ?? null,
                'vehicle_type' => $datos['vehicle_type'] ?? null,
                'color' => $datos['color'] ?? null,
                'mileage' => $datos['mileage'] ?? null,
                'fuel' => $datos['fuel'] ?? null,
                'transmission' => $datos['transmission'] ?? null,
                'plate' => $datos['plate'] ?? null,
                'purchase_cost' => $this->normalize((string) ($datos['purchase_cost'] ?? '0')),
                'asking_price' => $this->normalize((string) ($datos['asking_price'] ?? '0')),
                'min_price' => isset($datos['min_price']) && $datos['min_price'] !== ''
                    ? $this->normalize((string) $datos['min_price'])
                    : null,
                'acquired_at' => $datos['acquired_at'] ?? null,
                'notes' => $datos['notes'] ?? null,
            ]);

            // El rastro lo escribe la auditoría sola al guardar: guarda quién, cuándo, el valor
            // anterior y el nuevo. De ahí sale el historial de la ficha, sin tabla propia.
            $vehicle->save();

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

        $gasto = VehicleJob::create([
            'company_id' => $companyId,
            'vehicle_id' => $vehicle->id,
            'type' => ExpenseType::tryFrom($data->type) ?? ExpenseType::Reparacion,
            'description' => trim($data->description),
            'cost' => $this->normalize($data->cost),
            'performed_by' => $data->performedBy,
            'status' => $data->done ? JobStatus::Done : JobStatus::Pending,
            'performed_at' => $data->performedAt,
            'notes' => $data->notes,
            'user_id' => auth()->id(),
        ]);

        /*
         * El gasto sale de la caja igual que cualquier otro.
         *
         * Va por evento y no llamando a Finanzas aquí, para que el patio no dependa de ese módulo:
         * un dealer que no lo tenga contratado registra sus gastos igual, solo que nadie escucha. Es
         * el mismo camino que usan los préstamos.
         */
        VehicleExpenseRecorded::dispatch($gasto);

        return $gasto;
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
