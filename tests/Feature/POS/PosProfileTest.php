<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\POS\Support\PosProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('cada tipo trae su preset de opciones', function (): void {
    $salon = PosProfile::defaults('salon');
    expect($salon['tip'])->toBeTrue()
        ->and($salon['attendant'])->toBeTrue()
        ->and($salon['services'])->toBeTrue()
        ->and($salon['serial'])->toBeFalse();

    $tec = PosProfile::defaults('tecnologia');
    expect($tec['serial'])->toBeTrue()
        ->and($tec['line_note'])->toBeTrue()
        ->and($tec['tip'])->toBeFalse();
});

it('un tipo desconocido cae al preset por defecto', function (): void {
    expect(PosProfile::defaults('inventado'))->toBe(PosProfile::defaults(PosProfile::DEFAULT));
});

it('sin ajustes, la empresa usa el perfil general', function (): void {
    app(CurrentCompany::class)->forget();
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Perfil Co'));

    $config = PosProfile::for($company);

    expect($config['profile'])->toBe('general')
        ->and($config['options'])->toBe(PosProfile::defaults('general'));
});

it('los ajustes manuales se mezclan sobre los defaults del tipo', function (): void {
    app(CurrentCompany::class)->forget();
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajuste Co'));

    // Perfil salón pero apagando la propina a mano.
    $company->update(['settings' => ['pos' => ['profile' => 'salon', 'options' => ['tip' => false]]]]);

    $config = PosProfile::for($company->fresh());

    expect($config['profile'])->toBe('salon')
        ->and($config['options']['tip'])->toBeFalse()      // override manual
        ->and($config['options']['attendant'])->toBeTrue(); // sigue el preset del salón
});
