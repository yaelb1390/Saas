<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Printing\DTOs\SavePrinterData;
use App\Modules\Printing\Services\ModulePrinterResolver;
use App\Modules\Printing\Services\PrinterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * EL CEREBRO DEL BOTÓN «IMPRIMIR»: dado un módulo, ¿cuál impresora le toca?
 *
 * El orden es la regla completa: primero la asignada al módulo, si no la predeterminada de quien
 * imprime, si no ninguna (cae al diálogo del navegador). Cada test aquí prueba UN escalón del orden.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Resolver Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->registro = app(PrinterRegistry::class);
    $this->resolver = app(ModulePrinterResolver::class);
    $this->usuario = User::create(['company_id' => $this->company->id, 'name' => 'U', 'email' => 'u@res.test', 'password' => 'secret-password']);
});

it('sin nada asignado ni predeterminada, no resuelve ninguna', function (): void {
    expect($this->resolver->resolver('sales', $this->usuario, $this->company->fresh()))->toBeNull();
});

it('con solo la predeterminada del usuario, resuelve esa', function (): void {
    $mia = $this->registro->crear(SavePrinterData::fromArray(['name' => 'Mía', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']));
    $this->registro->marcarPredeterminada($this->usuario, $this->company->id, $mia);

    expect($this->resolver->resolver('sales', $this->usuario, $this->company->fresh())->id)->toBe($mia->id);
});

it('la impresora asignada al modulo gana sobre la predeterminada del usuario', function (): void {
    $delModulo = $this->registro->crear(SavePrinterData::fromArray(['name' => 'Del módulo', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']));
    $mia = $this->registro->crear(SavePrinterData::fromArray(['name' => 'Mía', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']));
    $this->registro->marcarPredeterminada($this->usuario, $this->company->id, $mia);

    $company = $this->company->fresh();
    $this->resolver->asignar($company, 'sales', $delModulo->id);

    expect($this->resolver->resolver('sales', $this->usuario, $company->fresh())->id)->toBe($delModulo->id);
});

it('si la asignada al modulo se borro, cae a la predeterminada del usuario', function (): void {
    $delModulo = $this->registro->crear(SavePrinterData::fromArray(['name' => 'Del módulo', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']));
    $mia = $this->registro->crear(SavePrinterData::fromArray(['name' => 'Mía', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']));
    $this->registro->marcarPredeterminada($this->usuario, $this->company->id, $mia);

    $company = $this->company->fresh();
    $this->resolver->asignar($company, 'sales', $delModulo->id);
    $this->registro->borrar($delModulo);

    expect($this->resolver->resolver('sales', $this->usuario, $company->fresh())->id)->toBe($mia->id);
});

it('asignar impresoras a dos modulos distintos no se pisan', function (): void {
    $ventas = $this->registro->crear(SavePrinterData::fromArray(['name' => 'Ventas', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']));
    $reportes = $this->registro->crear(SavePrinterData::fromArray(['name' => 'Reportes', 'connection_type' => 'browser', 'paper_size' => 'letter']));

    $company = $this->company->fresh();
    $this->resolver->asignar($company, 'sales', $ventas->id);
    $this->resolver->asignar($company->fresh(), 'reports', $reportes->id);

    $mapa = $this->resolver->modulesMap($company->fresh());
    expect($mapa['sales'])->toBe($ventas->id)->and($mapa['reports'])->toBe($reportes->id);
});
