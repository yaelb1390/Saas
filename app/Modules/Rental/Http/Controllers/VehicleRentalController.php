<?php

declare(strict_types=1);

namespace App\Modules\Rental\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\BusquedaTexto;
use App\Modules\Core\Support\CompanyLogoStore;
use App\Modules\Dealer\Enums\JobStatus;
use App\Modules\Dealer\Enums\VehicleStatus;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Models\VehicleJob;
use App\Modules\Rental\DTOs\CreateRentalData;
use App\Modules\Rental\Enums\RentalStatus;
use App\Modules\Rental\Exceptions\RentalException;
use App\Modules\Rental\Http\Requests\InspectionRequest;
use App\Modules\Rental\Http\Requests\RegisterRentalPaymentRequest;
use App\Modules\Rental\Http\Requests\StoreDamageRequest;
use App\Modules\Rental\Http\Requests\StoreRentalRequest;
use App\Modules\Rental\Models\VehicleDamage;
use App\Modules\Rental\Models\VehicleInspection;
use App\Modules\Rental\Models\VehicleRental;
use App\Modules\Rental\Services\VehicleDamageService;
use App\Modules\Rental\Services\VehicleRentalReportService;
use App\Modules\Rental\Services\VehicleRentalService;
use App\Modules\Rental\Support\VehicleInspectionPhotoStore;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Reservar, entregar, devolver y cobrar un alquiler de vehículo. */
final class VehicleRentalController extends Controller
{
    public function index(VehicleRentalReportService $reportes): View
    {
        $rentals = VehicleRental::query()
            ->with(['vehicle:id,code,make,model,year', 'customer:id,name'])
            ->when(request('estado'), fn ($q, $e) => $q->where('status', $e))
            ->when(request('q'), fn ($q, $texto) => BusquedaTexto::enCualquiera(
                $q, ['code'], (string) $texto,
            ))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('panel.rentals.index', [
            'rentals' => $rentals,
            'resumen' => $reportes->resumen(),
            'estados' => RentalStatus::cases(),
            // Solo los vehículos que se pueden alquilar por lo que su ficha dice —el solapamiento de
            // fechas se comprueba aparte, al elegir el rango—.
            'vehiculos' => Vehicle::query()
                ->where('usage_type', '!=', 'sale')
                ->whereNotIn('status', [VehicleStatus::Sold->value, VehicleStatus::Withdrawn->value])
                ->orderBy('make')->orderBy('model')
                ->get(['id', 'code', 'make', 'model', 'year', 'rental_price_daily', 'deposit_amount']),
        ]);
    }

    public function calendar(): View
    {
        return view('panel.rentals.calendar', [
            'estados' => RentalStatus::cases(),
            'vehiculos' => Vehicle::query()
                ->where('usage_type', '!=', 'sale')
                ->orderBy('make')->orderBy('model')
                ->get(['id', 'code', 'make', 'model', 'year']),
        ]);
    }

    /**
     * Los eventos del calendario: reservas/alquileres (colores por estado) y bloques de
     * mantenimiento programado (`VehicleJob` con próxima fecha) — pedido explícito de la Fase 18:
     * el calendario también tiene que enseñar los vehículos bloqueados, no solo lo alquilado.
     *
     * Filtra por vehículo, estado, cliente y rango de fechas, todo por query string.
     */
    public function calendarData(Request $request): JsonResponse
    {
        // Colores fijos por estado: los mismos cuatro que ya usa `RentalStatus::badgeClass()`, pero
        // en hexadecimal porque FullCalendar pinta el fondo del evento, no una clase de Tailwind.
        $colores = [
            'pending' => '#f59e0b', 'confirmed' => '#3b82f6', 'active' => '#8b5cf6',
            'returned' => '#64748b', 'completed' => '#10b981', 'cancelled' => '#ef4444',
        ];

        $rentals = VehicleRental::query()
            ->with(['vehicle:id,make,model,year', 'customer:id,name'])
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('status', $request->string('estado')))
            // Sin filtro de estado explícito, solo lo que de verdad ocupa el calendario hoy: nadie
            // quiere ver reservas canceladas de hace tres meses al abrir la pantalla.
            ->when(! $request->filled('estado'), fn ($q) => $q->whereIn('status', ['pending', 'confirmed', 'active', 'returned']))
            ->when($request->filled('start'), fn ($q) => $q->where('end_at', '>=', $request->date('start')))
            ->when($request->filled('end'), fn ($q) => $q->where('start_at', '<=', $request->date('end')))
            ->get()
            ->map(fn (VehicleRental $r): array => [
                'id' => 'alquiler-'.$r->id,
                'title' => $r->code.' · '.$r->vehicle?->nombre().' · '.$r->customer?->name,
                'start' => $r->start_at->toIso8601String(),
                'end' => $r->end_at->toIso8601String(),
                'url' => route('panel.rentals.show', $r),
                'backgroundColor' => $colores[$r->status->value] ?? '#64748b',
                'borderColor' => $colores[$r->status->value] ?? '#64748b',
            ]);

        $mantenimientos = VehicleJob::query()
            ->with('vehicle:id,make,model,year')
            ->whereIn('status', [JobStatus::Scheduled->value, JobStatus::InProgress->value])
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            // El mantenimiento no tiene cliente: si se filtró por cliente, no pinta ninguno.
            ->when($request->filled('customer_id'), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($request->filled('estado'), fn ($q) => $q->whereRaw('1 = 0'))
            ->get()
            ->filter(fn (VehicleJob $j) => $j->next_due_at !== null || $j->performed_at !== null)
            ->map(function (VehicleJob $j): array {
                $fecha = ($j->next_due_at ?? $j->performed_at)->toDateString();

                return [
                    'id' => 'mantenimiento-'.$j->id,
                    'title' => 'Mantenimiento: '.$j->vehicle?->nombre(),
                    'start' => $fecha,
                    'end' => $fecha,
                    'allDay' => true,
                    'backgroundColor' => '#dc2626',
                    'borderColor' => '#dc2626',
                ];
            });

        return response()->json($rentals->concat($mantenimientos)->values());
    }

    public function reports(VehicleRentalReportService $reportes): View
    {
        return view('panel.rentals.reports', [
            'resumen' => $reportes->resumen(),
            'ingresosPorMes' => $reportes->ingresosPorMes(),
            'masAlquilados' => $reportes->vehiculosMasAlquilados(),
        ]);
    }

    public function show(VehicleRental $rental): View
    {
        $rental->load(['vehicle', 'customer', 'payments.user', 'inspections.photos', 'damages']);

        return view('panel.rentals.show', ['rental' => $rental]);
    }

    /**
     * El contrato en PDF. Igual que las cotizaciones y los recibos de préstamo: se genera al vuelo
     * con dompdf, nunca se guarda una fila «Contract» — el alquiler mismo es la fuente de verdad.
     *
     * La firma va como imagen incrustada en base64: dompdf no manda cookies de sesión, así que no
     * puede pedirle al servidor una foto protegida por autenticación. Mismo motivo por el que el
     * logo de la empresa ya viaja como data URI.
     */
    public function contractPdf(VehicleRental $rental): Response
    {
        $mode = request()->query('modo', 'ver');

        $rental->load(['vehicle', 'customer', 'inspections' => fn ($q) => $q->orderBy('occurred_at')]);

        $firma = null;
        $entrega = $rental->pickupInspection();

        if ($entrega?->hasSignature()) {
            $disco = VehicleInspectionPhotoStore::disk();

            if ($disco->exists((string) $entrega->signature_path)) {
                $firma = 'data:image/png;base64,'.base64_encode((string) $disco->get((string) $entrega->signature_path));
            }
        }

        $pdf = Pdf::loadView('rentals.contract-pdf', [
            'rental' => $rental,
            'company' => $rental->company,
            'logo' => $rental->company?->hasLogo() ? CompanyLogoStore::dataUri($rental->company) : null,
            'firma' => $firma,
            'entrega' => $entrega,
        ])->setPaper('a4');

        $nombre = 'contrato-'.$rental->code.'.pdf';

        return $mode === 'descargar' ? $pdf->download($nombre) : $pdf->stream($nombre);
    }

    public function store(StoreRentalRequest $request, VehicleRentalService $rentals): RedirectResponse
    {
        $d = $request->validated();

        try {
            $rental = $rentals->reserve(new CreateRentalData(
                vehicleId: (int) $d['vehicle_id'],
                customerId: (int) $d['customer_id'],
                startAt: (string) $d['start_at'],
                endAt: (string) $d['end_at'],
                discount: (string) ($d['discount'] ?? '0'),
                depositOverride: isset($d['deposit_amount']) ? (string) $d['deposit_amount'] : null,
                notes: $d['notes'] ?? null,
                confirm: (bool) ($d['confirm'] ?? false),
            ));
        } catch (RentalException $e) {
            return back()->withInput()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Alquiler {$rental->code} reservado.");
    }

    public function confirm(VehicleRental $rental, VehicleRentalService $rentals): RedirectResponse
    {
        try {
            $rentals->confirm($rental);
        } catch (RentalException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Alquiler {$rental->code} confirmado.");
    }

    public function pickup(
        InspectionRequest $request,
        VehicleRental $rental,
        VehicleRentalService $rentals,
        VehicleInspectionPhotoStore $photos,
    ): RedirectResponse {
        try {
            $inspection = $rentals->pickup($rental, $request->validated());
        } catch (RentalException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        $this->guardarFotosYFirma($request, $inspection, $photos);

        return back()->with('panel_ok', "Vehículo entregado. Alquiler {$rental->code} en curso.");
    }

    public function returnVehicle(
        InspectionRequest $request,
        VehicleRental $rental,
        VehicleRentalService $rentals,
        VehicleDamageService $damages,
        VehicleInspectionPhotoStore $photos,
    ): RedirectResponse {
        try {
            $inspection = $rentals->returnVehicle($rental, $request->validated());
        } catch (RentalException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        $this->guardarFotosYFirma($request, $inspection, $photos);

        foreach ($request->validated('damages') ?? [] as $dano) {
            $damages->record($rental, [...$dano, 'vehicle_inspection_id' => $inspection->id]);
        }

        return back()->with('panel_ok', "Vehículo devuelto. Alquiler {$rental->code} listo para liquidar.");
    }

    public function settle(VehicleRental $rental, VehicleRentalService $rentals): RedirectResponse
    {
        try {
            $rentals->settle($rental);
        } catch (RentalException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Alquiler {$rental->code} cerrado.");
    }

    public function cancel(VehicleRental $rental, VehicleRentalService $rentals): RedirectResponse
    {
        try {
            $rentals->cancel($rental);
        } catch (RentalException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Alquiler {$rental->code} cancelado.");
    }

    public function payment(
        RegisterRentalPaymentRequest $request,
        VehicleRental $rental,
        VehicleRentalService $rentals,
    ): RedirectResponse {
        try {
            $rentals->registerPayment($rental, (string) $request->validated('amount'), [
                'method' => $request->validated('method'),
                'kind' => $request->validated('kind'),
                'reference' => $request->validated('reference'),
                'note' => $request->validated('note'),
            ]);
        } catch (RentalException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Abono registrado.');
    }

    public function damageStore(StoreDamageRequest $request, VehicleRental $rental, VehicleDamageService $damages): RedirectResponse
    {
        $damages->record($rental, $request->validated());

        return back()->with('panel_ok', 'Daño anotado.');
    }

    public function damageCharge(VehicleDamage $damage, VehicleDamageService $damages): RedirectResponse
    {
        $damages->charge($damage);

        return back()->with('panel_ok', 'Daño cobrado en el saldo del alquiler.');
    }

    public function damageWaive(VehicleDamage $damage, VehicleDamageService $damages): RedirectResponse
    {
        $damages->waive($damage);

        return back()->with('panel_ok', 'Daño condonado.');
    }

    private function guardarFotosYFirma(InspectionRequest $request, VehicleInspection $inspection, VehicleInspectionPhotoStore $store): void
    {
        foreach ($request->file('photos') ?? [] as $file) {
            $store->add($inspection, $file);
        }

        $firma = $request->validated('signature');

        if (filled($firma)) {
            $inspection->update(['signature_path' => $store->storeSignature($inspection, $firma)]);
        }
    }
}
