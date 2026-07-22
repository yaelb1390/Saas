<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\HR\DTOs\CreateEmployeeData;
use App\Modules\HR\Services\HrService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'HR Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->hr = app(HrService::class);
});

it('contrata un empleado', function (): void {
    $employee = $this->hr->hire(new CreateEmployeeData(name: 'Juan', position: 'Cajero'));

    expect($employee->name)->toBe('Juan')
        ->and($employee->is_active)->toBeTrue();
});

it('marca entrada y salida de asistencia', function (): void {
    $employee = $this->hr->hire(new CreateEmployeeData(name: 'Juan'));

    $attendance = $this->hr->clockIn($employee);
    expect($attendance->isOpen())->toBeTrue();

    $this->hr->clockOut($employee);
    expect($attendance->refresh()->clock_out)->not->toBeNull();
});

it('no permite dos entradas abiertas', function (): void {
    $employee = $this->hr->hire(new CreateEmployeeData(name: 'Juan'));
    $this->hr->clockIn($employee);

    expect(fn () => $this->hr->clockIn($employee))->toThrow(DomainException::class);
});
