<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Dealer\DTOs\CreateVehicleData;
use App\Modules\Dealer\Enums\VehicleStatus;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Services\VehicleService;
use App\Modules\Rental\DTOs\CreateRentalData;
use App\Modules\Rental\Enums\RentalStatus;
use App\Modules\Rental\Exceptions\RentalException;
use App\Modules\Rental\Models\VehicleRental;
use App\Modules\Rental\Services\VehicleAvailabilityService;
use App\Modules\Rental\Services\VehicleDamageService;
use App\Modules\Rental\Services\VehicleRentalReportService;
use App\Modules\Rental\Services\VehicleRentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/*
 * El módulo de Alquiler: reservar, entregar, devolver, liquidar y cobrar.
 *
 * Lo que más se vigila:
 *
 *   · QUE UN VEHÍCULO NO SE ALQUILE DOS VECES EN LAS MISMAS FECHAS. Es el fallo caro de este dominio,
 *     igual que la venta duplicada en el Dealer, pero con una vuelta más: aquí la disponibilidad no
 *     es un estado fijo, es un rango de fechas contra otras reservas.
 *   · QUE VENDIDO/EN TALLER BLOQUEE SIEMPRE, sea cual sea la fecha pedida.
 *   · QUE LOS KILÓMETROS DE MÁS SE CALCULEN SOLOS al devolver, y que los daños NO muevan el saldo
 *     hasta que alguien decida cobrarlos.
 */

uses(RefreshDatabase::class);

/** Un vehículo listo para alquilarse: tarifa, depósito y límite de km ya puestos. */
function vehiculoAlquilable(string $usageType = 'both'): Vehicle
{
    $vehiculo = app(VehicleService::class)->create(new CreateVehicleData(
        make: 'Toyota', model: 'Corolla', year: 2022,
        purchaseCost: '900000', askingPrice: '1200000',
        usageType: $usageType, rentalPriceDaily: '3500', depositAmount: '10000',
        rentalKmLimitDaily: 200, extraKmPrice: '15',
    ));

    // `VehicleService::create()` no acepta la tarifa semanal/mensual en el DTO todavía si no se
    // pasó; se deja así para no acoplar el test a más de lo que hace falta en cada caso.
    return $vehiculo;
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    DbTable::olvidar();

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Rent a Car RD'));
    $this->company->update(['modules' => ['dealer', 'rental', 'crm']]);
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@rentacar.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Cliente Uno']);
    $this->vehicle = vehiculoAlquilable();

    $this->rentals = app(VehicleRentalService::class);
});

afterEach(fn () => DbTable::olvidar());

// ------------------------------------------------------------------ Reservar y calcular el precio

it('reserva un vehículo y calcula el total con la tarifa diaria', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id,
        customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00',
        endAt: '2026-10-06 10:00:00', // 5 días
    ));

    expect($rental->status)->toBe(RentalStatus::Pending)
        ->and($rental->days)->toBe(5)
        ->and((string) $rental->daily_rate)->toBe('3500.00')
        ->and((string) $rental->subtotal)->toBe('17500.00')
        ->and((string) $rental->total)->toBe('17500.00')
        ->and((string) $rental->balance)->toBe('17500.00')
        ->and((string) $rental->deposit_amount)->toBe('10000.00')
        ->and($rental->code)->toStartWith('ALQ-');
});

it('aplica el descuento al total, nunca por debajo de cero', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-03 10:00:00', // 2 días = 7000
        discount: '50000', // más que el subtotal
    ));

    expect((string) $rental->total)->toBe('0.00');
});

// ------------------------------------------------------------------ Anti doble reserva

it('no deja reservar el mismo vehículo si las fechas se solapan', function (): void {
    $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-10 10:00:00', endAt: '2026-10-15 10:00:00',
    ));

    $otroCliente = Customer::create(['company_id' => $this->company->id, 'name' => 'Cliente Dos']);

    // Se solapa a mitad del rango ya reservado.
    expect(fn () => $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $otroCliente->id,
        startAt: '2026-10-12 10:00:00', endAt: '2026-10-18 10:00:00',
    )))->toThrow(RentalException::class);
});

it('sí deja reservar el mismo vehículo en fechas que NO se solapan', function (): void {
    $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-10 10:00:00', endAt: '2026-10-15 10:00:00',
    ));

    $otroCliente = Customer::create(['company_id' => $this->company->id, 'name' => 'Cliente Dos']);

    $segunda = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $otroCliente->id,
        startAt: '2026-10-15 10:00:00', endAt: '2026-10-18 10:00:00', // empieza justo cuando termina la otra
    ));

    expect($segunda->id)->not->toBeNull();
});

it('una reserva cancelada libera la fecha para otra', function (): void {
    $primera = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-10 10:00:00', endAt: '2026-10-15 10:00:00',
    ));

    $this->rentals->cancel($primera);

    $otroCliente = Customer::create(['company_id' => $this->company->id, 'name' => 'Cliente Dos']);

    expect(app(VehicleAvailabilityService::class)->isAvailable(
        $this->vehicle, Carbon::parse('2026-10-10'), Carbon::parse('2026-10-15'),
    ))->toBeTrue();
});

// ------------------------------------------------------------------ Estado del vehículo bloquea

it('un vehículo vendido no se puede reservar, sea cual sea la fecha', function (): void {
    $this->vehicle->update(['status' => VehicleStatus::Sold]);

    expect(fn () => $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-11-01 10:00:00', endAt: '2026-11-03 10:00:00',
    )))->toThrow(RentalException::class);
});

it('un vehículo en mantenimiento no se puede reservar', function (): void {
    $this->vehicle->update(['status' => VehicleStatus::Maintenance]);

    expect(fn () => $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-11-01 10:00:00', endAt: '2026-11-03 10:00:00',
    )))->toThrow(RentalException::class);
});

it('un vehículo marcado solo para venta no se puede reservar', function (): void {
    $soloVenta = vehiculoAlquilable('sale');

    expect(fn () => $this->rentals->reserve(new CreateRentalData(
        vehicleId: $soloVenta->id, customerId: $this->customer->id,
        startAt: '2026-11-01 10:00:00', endAt: '2026-11-03 10:00:00',
    )))->toThrow(RentalException::class);
});

// ------------------------------------------------------------------ Entrega y devolución

it('entregar el vehículo activa el alquiler y lo pone como Alquilado', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-06 10:00:00',
    ));

    $this->rentals->pickup($rental, ['mileage' => 10000, 'fuel_level' => 'full']);

    $rental->refresh();
    $this->vehicle->refresh();

    expect($rental->status)->toBe(RentalStatus::Active)
        ->and($rental->actual_pickup_at)->not->toBeNull()
        ->and($this->vehicle->status)->toBe(VehicleStatus::Rented);
});

it('devolver calcula los kilómetros de más y los suma al saldo', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-03 10:00:00', // 2 días, límite 200 km/día = 400
    ));

    $this->rentals->pickup($rental, ['mileage' => 10000, 'fuel_level' => 'full']);
    // 10000 -> 10450: 450 km recorridos, 400 permitidos, 50 de más a 15 c/u = 750.
    $this->rentals->returnVehicle($rental, ['mileage' => 10450, 'fuel_level' => 'half']);

    $rental->refresh();
    $this->vehicle->refresh();

    expect($rental->status)->toBe(RentalStatus::Returned)
        ->and($rental->kilometersUsed())->toBe(450)
        ->and((string) $rental->extra_km_charge)->toBe('750.00')
        ->and((string) $rental->total)->toBe('7750.00') // 7000 de tarifa + 750 de km
        ->and((string) $rental->balance)->toBe('7750.00')
        ->and($this->vehicle->status)->toBe(VehicleStatus::Available);
});

it('devolver sin pasarse del límite no cobra nada de más', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-03 10:00:00',
    ));

    $this->rentals->pickup($rental, ['mileage' => 10000, 'fuel_level' => 'full']);
    $this->rentals->returnVehicle($rental, ['mileage' => 10100, 'fuel_level' => 'full']);

    $rental->refresh();

    expect((float) ($rental->extra_km_charge ?? 0))->toBe(0.0)
        ->and((string) $rental->total)->toBe('7000.00');
});

it('liquidar cierra un alquiler devuelto', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-03 10:00:00',
    ));
    $this->rentals->pickup($rental, ['mileage' => 10000, 'fuel_level' => 'full']);
    $this->rentals->returnVehicle($rental, ['mileage' => 10050, 'fuel_level' => 'full']);

    $this->rentals->settle($rental);

    expect($rental->fresh()->status)->toBe(RentalStatus::Completed);
});

it('no se puede entregar un alquiler que ya está activo', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-03 10:00:00',
    ));
    $this->rentals->pickup($rental, ['mileage' => 10000, 'fuel_level' => 'full']);

    expect(fn () => $this->rentals->pickup($rental, ['mileage' => 10000, 'fuel_level' => 'full']))
        ->toThrow(RentalException::class);
});

// ------------------------------------------------------------------ Pagos

it('un abono descuenta del saldo', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-03 10:00:00', // total 7000
    ));

    $this->rentals->registerPayment($rental, '5000');

    expect((string) $rental->fresh()->balance)->toBe('2000.00');
});

it('rechaza un abono mayor que el saldo', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-03 10:00:00',
    ));

    expect(fn () => $this->rentals->registerPayment($rental, '999999'))->toThrow(RentalException::class);
});

it('rechaza un abono de cero o negativo', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-03 10:00:00',
    ));

    expect(fn () => $this->rentals->registerPayment($rental, '0'))->toThrow(RentalException::class);
});

// ------------------------------------------------------------------ Aislamiento por empresa

it('no ve ni deja reservar el vehículo de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra Rentadora'));
    $otra->update(['modules' => ['dealer', 'rental', 'crm']]);
    app(CurrentCompany::class)->set($otra->id);
    $clienteAjeno = Customer::create(['company_id' => $otra->id, 'name' => 'Cliente Ajeno']);
    app(CurrentCompany::class)->set($this->company->id);

    expect(fn () => $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $clienteAjeno->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-03 10:00:00',
    )))->toThrow(RentalException::class);
});

// ------------------------------------------------------------------ HTTP y permisos

it('la pantalla de alquiler responde y lista lo reservado', function (): void {
    $this->withoutVite();

    $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-03 10:00:00',
    ));

    $this->actingAs($this->owner)->get(route('panel.rentals'))
        ->assertOk()
        ->assertSee('ALQ-000001');
});

it('reservar por HTTP exige vehicle_rentals.manage, no solo vehicle_rentals.view', function (): void {
    $soloLectura = User::create([
        'company_id' => $this->company->id, 'name' => 'Solo lectura',
        'email' => 'lectura@rentacar.test', 'password' => 'secret-password',
    ]);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->company->id);
    $soloLectura->givePermissionTo('vehicle_rentals.view');
    $registrar->forgetCachedPermissions();

    $this->actingAs($soloLectura)->get(route('panel.rentals'))->assertOk();

    $this->actingAs($soloLectura)->post(route('panel.rentals.store'), [
        'vehicle_id' => $this->vehicle->id,
        'customer_id' => $this->customer->id,
        'start_at' => '2026-10-01 10:00:00',
        'end_at' => '2026-10-03 10:00:00',
    ])->assertForbidden();

    expect(VehicleRental::count())->toBe(0);
});

it('sin el módulo rental activo, la pantalla da 403 aunque tenga dealer', function (): void {
    $this->company->update(['modules' => ['dealer', 'crm']]); // sin 'rental'

    $this->actingAs($this->owner)->get(route('panel.rentals'))->assertForbidden();
});

// ------------------------------------------------------------------ Daños

it('un daño no mueve el saldo hasta que se cobra', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-03 10:00:00', // total 7000
    ));
    $this->rentals->pickup($rental, ['mileage' => 10000, 'fuel_level' => 'full']);
    $this->rentals->returnVehicle($rental, ['mileage' => 10050, 'fuel_level' => 'full']);

    $danos = app(VehicleDamageService::class);
    $dano = $danos->record($rental, [
        'category' => 'scratch', 'description' => 'Rayón en la puerta', 'amount' => '2000',
    ]);

    expect((string) $rental->fresh()->balance)->toBe('7000.00');

    $danos->charge($dano);

    expect((string) $rental->fresh()->balance)->toBe('9000.00')
        ->and((string) $rental->fresh()->total)->toBe('9000.00');
});

it('condonar un daño no cambia el saldo', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: '2026-10-01 10:00:00', endAt: '2026-10-03 10:00:00',
    ));

    $danos = app(VehicleDamageService::class);
    $dano = $danos->record($rental, ['category' => 'dent', 'description' => 'Golpe leve', 'amount' => '3000']);
    $danos->waive($dano);

    expect($dano->fresh()->status->value)->toBe('waived')
        ->and((string) $rental->fresh()->balance)->toBe('7000.00');
});

// ------------------------------------------------------------------ Resumen / rentabilidad

it('el resumen cuenta los alquilados ahora y lo cobrado este mes', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        // Fechas relativas a hoy: el resumen es «de este mes», y el test correrá cualquier día.
        startAt: now()->subDay()->toDateTimeString(), endAt: now()->addDays(4)->toDateTimeString(),
        confirm: true,
    ));
    $this->rentals->pickup($rental, ['mileage' => 100, 'fuel_level' => 'full']);
    $this->rentals->registerPayment($rental, '5000');

    $resumen = app(VehicleRentalReportService::class)->resumen();

    expect($resumen['alquilados_ahora'])->toBe(1)
        ->and((string) $resumen['ingresos_mes'])->toBe('5000.00');
});

it('el resumen no cuenta reservas que ya no bloquean nada', function (): void {
    $rental = $this->rentals->reserve(new CreateRentalData(
        vehicleId: $this->vehicle->id, customerId: $this->customer->id,
        startAt: now()->addDays(2)->toDateTimeString(), endAt: now()->addDays(5)->toDateTimeString(),
    ));
    $this->rentals->cancel($rental);

    $resumen = app(VehicleRentalReportService::class)->resumen();

    expect($resumen['alquilados_ahora'])->toBe(0)
        ->and($resumen['reservas_proximas'])->toBe(0);
});
