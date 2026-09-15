<?php

declare(strict_types=1);

namespace App\Modules\Rental\Services;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Dealer\Enums\VehicleStatus;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Rental\DTOs\CreateRentalData;
use App\Modules\Rental\Enums\RentalStatus;
use App\Modules\Rental\Events\RentalPaymentRegistered;
use App\Modules\Rental\Exceptions\RentalException;
use App\Modules\Rental\Models\VehicleInspection;
use App\Modules\Rental\Models\VehicleRental;
use App\Modules\Rental\Models\VehicleRentalPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reservar, entregar, devolver y cobrar un alquiler.
 *
 * Todo el dinero va como string con bcmath a dos decimales; nunca float — mismo criterio que el
 * Dealer y que Préstamos.
 */
final class VehicleRentalService
{
    private const SCALE = 2;

    public function __construct(
        private readonly VehicleAvailabilityService $availability,
        private readonly VehicleRentalPricingService $pricing,
    ) {}

    /**
     * Abre la reserva.
     *
     * EL CANDADO NO ES DECORATIVO, igual que en `VehicleDealService::open()`: dos personas
     * reservando el mismo vehículo a la vez es lo normal, y sin `lockForUpdate` las dos leerían
     * «disponible» antes de que la primera termine de guardar.
     */
    public function reserve(CreateRentalData $data): VehicleRental
    {
        return DB::transaction(function () use ($data): VehicleRental {
            $companyId = app(CurrentCompany::class)->id() ?? 0;

            $vehicle = Vehicle::query()->whereKey($data->vehicleId)->lockForUpdate()->firstOrFail();
            $customer = $this->resolveCustomer($data->customerId, $companyId);

            $start = Carbon::parse($data->startAt);
            $end = Carbon::parse($data->endAt);

            $this->availability->assertAvailable($vehicle, $start, $end);

            $precio = $this->pricing->calculate($vehicle, $start, $end, $data->discount, $data->depositOverride);

            $rental = VehicleRental::create([
                'company_id' => $companyId,
                'vehicle_id' => $vehicle->id,
                'customer_id' => $customer->id,
                'code' => $this->nextCode($companyId),
                'start_at' => $start,
                'end_at' => $end,
                'daily_rate' => $precio['dailyRate'],
                'days' => $precio['days'],
                'discount' => $precio['discount'],
                'deposit_amount' => $precio['deposit'],
                'subtotal' => $precio['subtotal'],
                'total' => $precio['total'],
                'balance' => $precio['total'],
                'status' => $data->confirm ? RentalStatus::Confirmed : RentalStatus::Pending,
                'notes' => $data->notes,
                'user_id' => auth()->id(),
            ]);

            return $rental;
        });
    }

    /** Confirma una reserva pendiente. Vuelve a comprobar disponibilidad: pudo cambiar algo desde que se pidió. */
    public function confirm(VehicleRental $rental): VehicleRental
    {
        return DB::transaction(function () use ($rental): VehicleRental {
            if ($rental->status !== RentalStatus::Pending) {
                throw RentalException::alquilerCerrado();
            }

            $vehicle = Vehicle::query()->whereKey($rental->vehicle_id)->lockForUpdate()->firstOrFail();
            $this->availability->assertAvailable($vehicle, $rental->start_at, $rental->end_at, $rental->id);

            $rental->status = RentalStatus::Confirmed;
            $rental->save();

            return $rental;
        });
    }

    /**
     * Entrega el vehículo: registra la inspección de recogida y arranca el alquiler.
     *
     * @param  array<string, mixed>  $inspectionData
     * @param  array<int, string>  $damages  Daños PREVIOS anotados en la entrega (poco frecuentes,
     *                                       pero pasa: el cliente hace notar un rayón que ya traía).
     */
    public function pickup(VehicleRental $rental, array $inspectionData): VehicleInspection
    {
        return DB::transaction(function () use ($rental, $inspectionData): VehicleInspection {
            if (! in_array($rental->status, [RentalStatus::Pending, RentalStatus::Confirmed], true)) {
                throw RentalException::noAdmiteEntrega();
            }

            $vehicle = Vehicle::query()->whereKey($rental->vehicle_id)->lockForUpdate()->firstOrFail();

            if (! $vehicle->status->admiteAlquiler()) {
                throw RentalException::noDisponible($vehicle->nombre(), mb_strtolower($vehicle->status->label()));
            }

            $inspection = $rental->inspections()->create([
                'company_id' => $rental->company_id,
                'type' => 'pickup',
                'mileage' => $inspectionData['mileage'],
                'fuel_level' => $inspectionData['fuel_level'],
                'exterior_condition' => $inspectionData['exterior_condition'] ?? null,
                'interior_condition' => $inspectionData['interior_condition'] ?? null,
                'accessories' => $inspectionData['accessories'] ?? null,
                'checklist' => $inspectionData['checklist'] ?? null,
                'observations' => $inspectionData['observations'] ?? null,
                'user_id' => auth()->id(),
                'occurred_at' => now(),
            ]);

            $vehicle->status = VehicleStatus::Rented;
            $vehicle->save();

            $rental->status = RentalStatus::Active;
            $rental->actual_pickup_at = now();
            $rental->save();

            return $inspection;
        });
    }

    /**
     * Devuelve el vehículo: registra la inspección de devolución, calcula los kilómetros de más y
     * cierra el alquiler como «Devuelto» (no como «Cerrado»: liquidar la cuenta es un paso aparte,
     * ver `settle()`).
     *
     * @param  array<string, mixed>  $inspectionData
     */
    public function returnVehicle(VehicleRental $rental, array $inspectionData): VehicleInspection
    {
        return DB::transaction(function () use ($rental, $inspectionData): VehicleInspection {
            if ($rental->status !== RentalStatus::Active) {
                throw RentalException::noAdmiteDevolucion();
            }

            $vehicle = Vehicle::query()->whereKey($rental->vehicle_id)->lockForUpdate()->firstOrFail();

            $inspection = $rental->inspections()->create([
                'company_id' => $rental->company_id,
                'type' => 'return',
                'mileage' => $inspectionData['mileage'],
                'fuel_level' => $inspectionData['fuel_level'],
                'exterior_condition' => $inspectionData['exterior_condition'] ?? null,
                'interior_condition' => $inspectionData['interior_condition'] ?? null,
                'accessories' => $inspectionData['accessories'] ?? null,
                'checklist' => $inspectionData['checklist'] ?? null,
                'observations' => $inspectionData['observations'] ?? null,
                'user_id' => auth()->id(),
                'occurred_at' => now(),
            ]);

            // Los kilómetros de más se calculan y se suman al saldo DE UNA VEZ: es un número objetivo,
            // no hace falta que nadie lo decida. Los daños son distintos —hay que decidir si se
            // cobran o se condonan— y por eso no tocan el saldo aquí (ver VehicleDamageService).
            $rental->load('inspections');
            $usados = $rental->kilometersUsed();

            if ($usados !== null) {
                $extra = $this->pricing->extraKmCharge($vehicle, (int) $rental->days, $usados);

                if (bccomp($extra, '0', self::SCALE) > 0) {
                    $rental->extra_km_charge = $extra;
                    $rental->total = bcadd($rental->total, $extra, self::SCALE);
                    $rental->balance = bcadd($rental->balance, $extra, self::SCALE);
                }
            }

            $rental->status = RentalStatus::Returned;
            $rental->actual_return_at = now();
            $rental->save();

            // El vehículo vuelve al patio. Si le quedó algún daño que lo saque de circulación, quien
            // atiende lo pasa a «En mantenimiento» a mano desde la ficha del vehículo —esta operación
            // no lo decide por su cuenta—.
            $vehicle->status = VehicleStatus::Available;
            $vehicle->save();

            return $inspection;
        });
    }

    /** Cierra la cuenta del alquiler. No exige saldo en cero: es una decisión administrativa. */
    public function settle(VehicleRental $rental): VehicleRental
    {
        if ($rental->status !== RentalStatus::Returned) {
            throw RentalException::noAdmiteDevolucion();
        }

        $rental->status = RentalStatus::Completed;
        $rental->save();

        return $rental;
    }

    /** Cancela una reserva que todavía no se entregó. La fecha simplemente queda libre. */
    public function cancel(VehicleRental $rental): VehicleRental
    {
        if (! $rental->status->admiteCancelacion()) {
            throw RentalException::noAdmiteCancelacion();
        }

        $rental->status = RentalStatus::Cancelled;
        $rental->save();

        return $rental;
    }

    /**
     * Registra un abono o cargo. Mismo reparto que en Dealer/Préstamos: se descuenta del saldo bajo
     * candado, para que dos cobros a la vez no dejen el saldo descuadrado.
     *
     * @param  array<string, mixed>  $context
     */
    public function registerPayment(VehicleRental $rental, string $amount, array $context = []): VehicleRentalPayment
    {
        return DB::transaction(function () use ($rental, $amount, $context): VehicleRentalPayment {
            $rental = VehicleRental::query()->whereKey($rental->id)->lockForUpdate()->firstOrFail();

            if (! $rental->status->admitePago()) {
                throw RentalException::alquilerCancelado();
            }

            $abono = $this->normalize($amount);

            if (bccomp($abono, '0', self::SCALE) <= 0) {
                throw RentalException::abonoInvalido();
            }

            $saldo = $this->normalize($rental->balance ?? '0');

            if (bccomp($abono, $saldo, self::SCALE) > 0) {
                throw RentalException::abonoMayorQueElSaldo(number_format((float) $saldo, 2));
            }

            $pago = VehicleRentalPayment::create([
                'company_id' => $rental->company_id,
                'vehicle_rental_id' => $rental->id,
                'amount' => $abono,
                'method' => $context['method'] ?? 'cash',
                'kind' => $context['kind'] ?? 'rental',
                'reference' => $context['reference'] ?? null,
                'paid_at' => now(),
                'notes' => $context['note'] ?? null,
                'user_id' => auth()->id(),
            ]);

            $rental->balance = bcsub($saldo, $abono, self::SCALE);
            $rental->save();

            RentalPaymentRegistered::dispatch($pago);

            return $pago;
        });
    }

    private function resolveCustomer(int $customerId, int $companyId): Customer
    {
        $customer = Customer::withoutGlobalScopes()->whereKey($customerId)->first();

        if ($customer === null || (int) $customer->company_id !== $companyId) {
            throw RentalException::clienteDeOtraEmpresa();
        }

        return $customer;
    }

    private function nextCode(int $companyId): string
    {
        $count = VehicleRental::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->count();

        return 'ALQ-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    private function normalize(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }
}
