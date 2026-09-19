<?php

declare(strict_types=1);

namespace App\Modules\Rental\Services;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Dealer\Enums\VehicleStatus;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Models\VehicleJob;
use App\Modules\Rental\Models\VehicleRental;
use App\Modules\Rental\Models\VehicleRentalPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Las cifras de la pantalla de Alquiler: cuántos vehículos están fuera ahora mismo, lo que entró
 * este mes y la utilidad después de restar el mantenimiento.
 *
 * Todo bcmath, como el resto del módulo. Se calcula en vivo —nada se persiste—, mismo criterio que
 * `Vehicle::costoReal()`/`margen()` en el Dealer: un total guardado hay que acordarse de actualizarlo,
 * y el día que alguien no lo haga la cifra miente sin avisar.
 */
final class VehicleRentalReportService
{
    private const SCALE = 2;

    /** Segundos que se sirven los indicadores desde caché antes de recalcularlos. */
    private const TTL = 60;

    /**
     * @return array{
     *     alquilados_ahora: int, reservas_proximas: int, ingresos_mes: string,
     *     gastos_mes: string, utilidad_mes: string, valor_flota: string, tasa_utilizacion: string,
     * }
     */
    public function resumen(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? 0;

        return Cache::remember(
            "company:{$companyId}:rental-resumen",
            self::TTL,
            fn (): array => $this->computeResumen(),
        );
    }

    /**
     * Cálculo real del resumen (sin caché). Separado para poder cachearlo y para poder probar el
     * valor fresco.
     *
     * @return array{
     *     alquilados_ahora: int, reservas_proximas: int, ingresos_mes: string,
     *     gastos_mes: string, utilidad_mes: string, valor_flota: string, tasa_utilizacion: string,
     * }
     */
    public function computeResumen(): array
    {
        $hoy = now();
        $inicioMes = $hoy->copy()->startOfMonth();
        $finMes = $hoy->copy()->endOfMonth();

        $flota = Vehicle::query()->whereIn('usage_type', ['rental', 'both']);

        $ingresosMes = (string) VehicleRentalPayment::query()
            ->whereBetween('paid_at', [$inicioMes, $finMes])
            ->sum('amount');

        $gastosMes = (string) VehicleJob::query()
            ->whereHas('vehicle', fn ($q) => $q->whereIn('usage_type', ['rental', 'both']))
            ->whereBetween('performed_at', [$inicioMes->toDateString(), $finMes->toDateString()])
            ->sum('cost');

        return [
            'alquilados_ahora' => VehicleRental::query()->where('status', 'active')->count(),
            'reservas_proximas' => VehicleRental::query()
                ->whereIn('status', ['pending', 'confirmed'])
                ->where('start_at', '>=', $hoy)
                ->count(),
            'ingresos_mes' => $this->normalize($ingresosMes),
            'gastos_mes' => $this->normalize($gastosMes),
            'utilidad_mes' => bcsub($this->normalize($ingresosMes), $this->normalize($gastosMes), self::SCALE),
            'valor_flota' => (string) (clone $flota)->sum('purchase_cost'),
            'tasa_utilizacion' => $this->tasaUtilizacion((clone $flota)->get(), $inicioMes, $finMes),
        ];
    }

    /**
     * Qué proporción de los días disponibles de la flota (vehículos de alquiler) estuvo alquilada
     * este mes. Un vehículo que entró a mitad de mes solo cuenta desde que entró —no antes—, así una
     * unidad nueva no hace bajar la tasa de las demás sin haber tenido oportunidad de alquilarse.
     *
     * @param  Collection<int, Vehicle>  $vehiculos
     */
    private function tasaUtilizacion($vehiculos, Carbon $inicioMes, Carbon $finMes): string
    {
        if ($vehiculos->isEmpty()) {
            return '0.00';
        }

        // UNA sola consulta para TODA la flota, no una por vehículo. El límite inferior se
        // ensancha a $inicioMes (en vez del $desde de cada vehículo, que solo se conoce dentro del
        // bucle): no cambia el resultado, porque la comprobación de solape de más abajo ya descarta
        // exactamente las mismas filas de más que un `where('end_at', '>=', $desde)` por vehículo
        // habría filtrado antes.
        $alquileresPorVehiculo = VehicleRental::query()
            ->whereIn('vehicle_id', $vehiculos->pluck('id'))
            ->whereIn('status', ['active', 'returned', 'completed'])
            ->where('start_at', '<=', $finMes)
            ->where('end_at', '>=', $inicioMes)
            ->get(['vehicle_id', 'start_at', 'end_at'])
            ->groupBy('vehicle_id');

        $diasDisponiblesTotal = 0;
        $diasAlquiladosTotal = 0;

        foreach ($vehiculos as $vehiculo) {
            $desde = $vehiculo->acquired_at !== null && $vehiculo->acquired_at->greaterThan($inicioMes)
                ? $vehiculo->acquired_at->copy()->startOfDay()
                : $inicioMes;

            if ($desde->greaterThan($finMes)) {
                continue;
            }

            $diasDisponiblesTotal += (int) $desde->diffInDays($finMes) + 1;

            foreach ($alquileresPorVehiculo->get($vehiculo->id, collect()) as $alquiler) {
                $solapaDesde = $alquiler->start_at->max($desde);
                $solapaHasta = $alquiler->end_at->min($finMes);

                if ($solapaHasta->greaterThanOrEqualTo($solapaDesde)) {
                    $diasAlquiladosTotal += (int) $solapaDesde->diffInDays($solapaHasta) + 1;
                }
            }
        }

        if ($diasDisponiblesTotal === 0) {
            return '0.00';
        }

        return bcdiv(bcmul((string) $diasAlquiladosTotal, '100', self::SCALE), (string) $diasDisponiblesTotal, self::SCALE);
    }

    /**
     * Las cuatro tarjetas de la cabecera del calendario: de la flota que se alquila, cuántos están
     * libres, reservados, en curso o en el taller ahora mismo.
     *
     * «Disponibles» se resta de las otras tres: es la única de las cuatro que no tiene una consulta
     * propia —es lo que sobra de la flota una vez descontado lo demás—, así que si un vehículo se
     * cuenta dos veces en otro lado, aquí se nota como que faltan disponibles, no como un número que
     * calla el error.
     *
     * @return array{disponibles: int, reservados: int, en_curso: int, mantenimiento: int}
     */
    public function estadoFlota(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? 0;

        return Cache::remember(
            "company:{$companyId}:rental-fleet-status",
            self::TTL,
            fn (): array => $this->computeEstadoFlota(),
        );
    }

    /**
     * Cálculo real del estado de la flota (sin caché).
     *
     * Lo usa el endpoint de resumen del calendario (`calendarSummary()`), que el propio calendario
     * vuelve a pedir justo después de confirmar/cancelar/liquidar una reserva: si esa llamada
     * sirviera la versión cacheada, quien acaba de cancelar un alquiler vería sus propias tarjetas
     * desactualizadas hasta por un minuto. La carga de página normal SÍ puede cachearse (ver
     * estadoFlota() arriba) — es la misma separación que ya usa executiveSummary()/
     * computeExecutiveSummary() en ReportService.
     *
     * @return array{disponibles: int, reservados: int, en_curso: int, mantenimiento: int}
     */
    public function computeEstadoFlota(): array
    {
        $flota = Vehicle::query()->whereIn('usage_type', ['rental', 'both'])->get(['id', 'status']);

        $mantenimiento = $flota->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Maintenance)->count();
        $enCurso = $flota->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Rented)->count();

        $reservados = VehicleRental::query()
            ->whereIn('status', ['pending', 'confirmed'])
            ->distinct('vehicle_id')
            ->count('vehicle_id');

        return [
            'disponibles' => max(0, $flota->count() - $mantenimiento - $enCurso - $reservados),
            'reservados' => $reservados,
            'en_curso' => $enCurso,
            'mantenimiento' => $mantenimiento,
        ];
    }

    /**
     * Ingresos cobrados por mes, los últimos N meses (incluido el actual).
     *
     * @return list<array{mes: string, total: string}>
     */
    public function ingresosPorMes(int $meses = 6): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? 0;

        return Cache::remember(
            "company:{$companyId}:rental-revenue-by-month:{$meses}",
            self::TTL,
            fn (): array => $this->computeIngresosPorMes($meses),
        );
    }

    /**
     * @return list<array{mes: string, total: string}>
     */
    public function computeIngresosPorMes(int $meses): array
    {
        $inicio = now()->copy()->subMonths($meses - 1)->startOfMonth();

        $pagos = VehicleRentalPayment::query()
            ->where('paid_at', '>=', $inicio)
            ->get(['amount', 'paid_at']);

        $porMes = [];

        for ($i = 0; $i < $meses; $i++) {
            $mes = $inicio->copy()->addMonths($i);
            $porMes[$mes->format('Y-m')] = ['mes' => $mes->translatedFormat('M Y'), 'total' => '0.00'];
        }

        foreach ($pagos as $pago) {
            $clave = $pago->paid_at->format('Y-m');

            if (isset($porMes[$clave])) {
                $porMes[$clave]['total'] = bcadd($porMes[$clave]['total'], (string) $pago->amount, self::SCALE);
            }
        }

        return array_values($porMes);
    }

    /**
     * Los vehículos con más alquileres, con sus días totales alquilado y lo que generaron. Solo
     * cuenta alquileres que de verdad se entregaron —una reserva cancelada no es un alquiler—.
     *
     * @return list<array{vehiculo: string, alquileres: int, dias: int, ingresos: string}>
     */
    public function vehiculosMasAlquilados(int $top = 10): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? 0;

        return Cache::remember(
            "company:{$companyId}:rental-top-vehicles:{$top}",
            self::TTL,
            fn (): array => $this->computeVehiculosMasAlquilados($top),
        );
    }

    /**
     * @return list<array{vehiculo: string, alquileres: int, dias: int, ingresos: string}>
     */
    public function computeVehiculosMasAlquilados(int $top): array
    {
        return VehicleRental::query()
            ->whereIn('status', ['active', 'returned', 'completed'])
            ->with('vehicle:id,make,model,year')
            ->get()
            ->groupBy('vehicle_id')
            ->map(function ($alquileres) {
                $vehiculo = $alquileres->first()->vehicle;

                return [
                    'vehiculo' => $vehiculo?->nombre() ?? '—',
                    'alquileres' => $alquileres->count(),
                    'dias' => (int) $alquileres->sum('days'),
                    'ingresos' => $alquileres->reduce(
                        fn (string $acc, VehicleRental $r): string => bcadd($acc, $r->paid(), self::SCALE),
                        '0.00',
                    ),
                ];
            })
            ->sortByDesc('dias')
            ->take($top)
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }
}
