<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Printing\DTOs\SavePrinterData;
use App\Modules\Printing\Enums\PrinterStatus;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Services\PrinterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * EL REGISTRO DE IMPRESORAS: alta, edición, baja, estado y predeterminada por usuario.
 *
 * No habla con hardware —eso lo hace el navegador—; esto es la ficha. Lo que sí es domain logic real
 * aquí es que la predeterminada es POR USUARIO, no por empresa: la térmica está atornillada a una
 * caja, no a todo el negocio.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Registro Co'));
    app(CurrentCompany::class)->set($this->company->id);
    $this->registry = app(PrinterRegistry::class);
});

it('crea una impresora con sus ajustes', function (): void {
    $printer = $this->registry->crear(SavePrinterData::fromArray([
        'name' => 'Térmica caja 1',
        'connection_type' => 'bluetooth',
        'paper_size' => '80mm',
        'settings' => ['auto_cut' => true, 'default_copies' => 2],
    ]));

    expect($printer->id)->not->toBeNull()
        ->and($printer->name)->toBe('Térmica caja 1')
        ->and($printer->esBluetooth())->toBeTrue()
        ->and($printer->settings['default_copies'])->toBe(2)
        ->and($printer->last_status)->toBe(PrinterStatus::Disponible);
});

it('un tamaño personalizado guarda ancho y alto propios', function (): void {
    $printer = $this->registry->crear(SavePrinterData::fromArray([
        'name' => 'Etiquetadora', 'connection_type' => 'usb', 'paper_size' => 'custom',
        'custom_width_mm' => '62', 'custom_height_mm' => '29',
    ]));

    expect($printer->anchoMm())->toBe(62)->and($printer->altoMm())->toBe(29);
});

it('un rollo termico no tiene alto fijo', function (): void {
    $printer = $this->registry->crear(SavePrinterData::fromArray(['name' => 'T', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']));

    expect($printer->altoMm())->toBeNull();
});

it('borrar una impresora la archiva, no la destruye: el historial la sigue viendo', function (): void {
    $printer = $this->registry->crear(SavePrinterData::fromArray(['name' => 'X', 'connection_type' => 'browser', 'paper_size' => 'a4']));

    $this->registry->borrar($printer);

    expect(Printer::find($printer->id))->toBeNull()
        ->and(Printer::withTrashed()->find($printer->id))->not->toBeNull();
});

it('marcar estado actualiza el estado y la ultima vez que se vio', function (): void {
    $printer = $this->registry->crear(SavePrinterData::fromArray(['name' => 'X', 'connection_type' => 'browser', 'paper_size' => 'a4']));

    $this->registry->marcarEstado($printer, PrinterStatus::Conectada);

    expect($printer->fresh()->last_status)->toBe(PrinterStatus::Conectada)
        ->and($printer->fresh()->last_seen_at)->not->toBeNull();
});

/*
 * LA PREDETERMINADA ES POR USUARIO. Dos cajeros, cada uno con la suya, sin pisarse.
 */
it('cada usuario tiene su propia impresora predeterminada', function (): void {
    $a = $this->registry->crear(SavePrinterData::fromArray(['name' => 'Caja 1', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']));
    $b = $this->registry->crear(SavePrinterData::fromArray(['name' => 'Caja 2', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']));

    $cajero1 = User::create(['company_id' => $this->company->id, 'name' => 'C1', 'email' => 'c1@r.test', 'password' => 'secret-password']);
    $cajero2 = User::create(['company_id' => $this->company->id, 'name' => 'C2', 'email' => 'c2@r.test', 'password' => 'secret-password']);

    $this->registry->marcarPredeterminada($cajero1, $this->company->id, $a);
    $this->registry->marcarPredeterminada($cajero2, $this->company->id, $b);

    expect($this->registry->predeterminadaDe($cajero1, $this->company->id)->id)->toBe($a->id)
        ->and($this->registry->predeterminadaDe($cajero2, $this->company->id)->id)->toBe($b->id);
});

it('quitar la predeterminada la deja en null sin borrar la impresora', function (): void {
    $printer = $this->registry->crear(SavePrinterData::fromArray(['name' => 'X', 'connection_type' => 'browser', 'paper_size' => 'a4']));
    $usuario = User::create(['company_id' => $this->company->id, 'name' => 'U', 'email' => 'u@r.test', 'password' => 'secret-password']);

    $this->registry->marcarPredeterminada($usuario, $this->company->id, $printer);
    $this->registry->marcarPredeterminada($usuario, $this->company->id, null);

    expect($this->registry->predeterminadaDe($usuario, $this->company->id))->toBeNull()
        ->and(Printer::find($printer->id))->not->toBeNull();
});
