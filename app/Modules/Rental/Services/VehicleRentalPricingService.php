<?php

declare(strict_types=1);

namespace App\Modules\Rental\Services;

use App\Modules\Dealer\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * Cuánto cuesta alquilar un vehículo en un rango de fechas.
 *
 * Todo en bcmath, nunca floats —un alquiler de varios días son miles de pesos, y el redondeo binario
 * se nota justo ahí—. La moneda NUNCA se decide aquí: esto devuelve cifras desnudas, y quien las
 * pinte usa el helper `money()` ya existente, que resuelve el símbolo de la empresa activa.
 */
final class VehicleRentalPricingService
{
    private const SCALE = 2;

    /**
     * @return array{days: int, dailyRate: string, subtotal: string, discount: string,
     *               deposit: string, total: string}
     */
    public function calculate(
        Vehicle $vehicle,
        Carbon $start,
        Carbon $end,
        string $discount = '0',
        ?string $depositOverride = null,
    ): array {
        // Días de calendario, no horas exactas: quien alquila por horas dentro del mismo día igual
        // paga el día completo, que es como se cobra un alquiler de verdad.
        $dias = max(1, (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()));
        $tarifaDiaria = $this->tarifaAplicable($vehicle, $dias);

        $subtotal = bcmul($tarifaDiaria, (string) $dias, self::SCALE);
        $descuento = $this->normalize($discount);
        $total = bcsub($subtotal, $descuento, self::SCALE);
        $total = bccomp($total, '0', self::SCALE) < 0 ? '0.00' : $total;

        $deposito = $depositOverride !== null
            ? $this->normalize($depositOverride)
            : $this->normalize((string) ($vehicle->deposit_amount ?? '0'));

        return [
            'days' => $dias,
            'dailyRate' => $tarifaDiaria,
            'subtotal' => $subtotal,
            'discount' => $descuento,
            'deposit' => $deposito,
            'total' => $total,
        ];
    }

    /**
     * La tarifa DIARIA equivalente, eligiendo la semanal o mensual del vehículo cuando el rango encaja
     * exacto y sale más barata —igual que le conviene al cliente que alquila una semana completa—.
     * Si el vehículo no define esas tarifas, o el rango no encaja, se usa siempre la diaria.
     */
    private function tarifaAplicable(Vehicle $vehicle, int $dias): string
    {
        $diaria = $this->normalize((string) ($vehicle->rental_price_daily ?? '0'));

        if ($dias % 30 === 0 && filled($vehicle->rental_price_monthly)) {
            $equivalente = bcdiv((string) $vehicle->rental_price_monthly, '30', self::SCALE + 2);

            if (bccomp($equivalente, $diaria, self::SCALE) < 0) {
                return $this->normalize($equivalente);
            }
        }

        if ($dias % 7 === 0 && filled($vehicle->rental_price_weekly)) {
            $equivalente = bcdiv((string) $vehicle->rental_price_weekly, '7', self::SCALE + 2);

            if (bccomp($equivalente, $diaria, self::SCALE) < 0) {
                return $this->normalize($equivalente);
            }
        }

        return $diaria;
    }

    /** Lo que se cobra de más por pasarse del límite de kilómetros pactado. */
    public function extraKmCharge(Vehicle $vehicle, int $days, int $kilometersUsed): string
    {
        $limite = $vehicle->rental_km_limit_daily;

        if ($limite === null) {
            return '0.00';
        }

        $permitidos = $limite * $days;

        if ($kilometersUsed <= $permitidos) {
            return '0.00';
        }

        $exceso = (string) ($kilometersUsed - $permitidos);
        $precioPorKm = $this->normalize((string) ($vehicle->extra_km_price ?? '0'));

        return bcmul($exceso, $precioPorKm, self::SCALE);
    }

    private function normalize(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }
}
