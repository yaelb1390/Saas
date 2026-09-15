<?php

declare(strict_types=1);

namespace App\Modules\Rental\Services;

use App\Modules\Rental\Enums\DamageStatus;
use App\Modules\Rental\Models\VehicleDamage;
use App\Modules\Rental\Models\VehicleRental;
use Illuminate\Support\Facades\DB;

/**
 * Registrar, cobrar o condonar un daño.
 *
 * Cobrar un daño es una DECISIÓN, no un cálculo automático —al revés que los kilómetros de más, que
 * `VehicleRentalService::returnVehicle()` ya suma solo—: por eso el monto del daño no entra al saldo
 * del alquiler hasta que alguien pulsa «cobrar».
 */
final class VehicleDamageService
{
    private const SCALE = 2;

    /** @param  array<string, mixed>  $data */
    public function record(VehicleRental $rental, array $data): VehicleDamage
    {
        return VehicleDamage::create([
            'company_id' => $rental->company_id,
            'vehicle_rental_id' => $rental->id,
            'vehicle_inspection_id' => $data['vehicle_inspection_id'] ?? null,
            'category' => $data['category'],
            'description' => $data['description'],
            'amount' => $data['amount'] ?? null,
            'responsible' => $data['responsible'] ?? null,
            'status' => DamageStatus::Pending,
            'user_id' => auth()->id(),
        ]);
    }

    /** Convierte el daño en cargo del alquiler: suma su monto al total y al saldo. */
    public function charge(VehicleDamage $damage): VehicleDamage
    {
        return DB::transaction(function () use ($damage): VehicleDamage {
            if ($damage->status === DamageStatus::Charged) {
                return $damage;
            }

            $monto = bcadd((string) ($damage->amount ?? '0'), '0', self::SCALE);

            if ($damage->rental !== null && bccomp($monto, '0', self::SCALE) > 0) {
                $rental = VehicleRental::query()->whereKey($damage->vehicle_rental_id)->lockForUpdate()->first();

                if ($rental !== null) {
                    $rental->damage_charge = bcadd((string) ($rental->damage_charge ?? '0'), $monto, self::SCALE);
                    $rental->total = bcadd($rental->total, $monto, self::SCALE);
                    $rental->balance = bcadd($rental->balance, $monto, self::SCALE);
                    $rental->save();
                }
            }

            $damage->status = DamageStatus::Charged;
            $damage->save();

            return $damage;
        });
    }

    public function waive(VehicleDamage $damage): VehicleDamage
    {
        $damage->status = DamageStatus::Waived;
        $damage->save();

        return $damage;
    }
}
