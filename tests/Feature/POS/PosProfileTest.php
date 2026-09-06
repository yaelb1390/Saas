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

/*
 * TODO INTERRUPTOR DE ESTA PANTALLA TIENE QUE HACER ALGO.
 *
 * Había uno —«Servicios (sin stock)»— que no tenía un solo consumidor en el proyecto: se encendía y
 * se apagaba y no cambiaba nada. Vender servicios ya funciona por `products.track_stock`, que sí
 * está conectado.
 *
 * Un interruptor que no hace nada es peor que no tenerlo: enseña al dueño que la pantalla de ajustes
 * miente, y el día que dude de uno que SÍ importa —los del descuento, que se comprueban en el
 * servidor— no sabrá de cuáles fiarse. Este test está para que no vuelva a colarse uno así.
 */
it('ningun interruptor del terminal es decorativo', function (): void {
    // Se recorre el codigo con PHP y no con `grep`: un test que depende de las herramientas del
    // sistema falla en la maquina de otro por un motivo que no tiene nada que ver con lo que prueba.
    $fuentes = [];

    foreach ([base_path('app'), base_path('resources')] as $raiz) {
        $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz));

        foreach ($iterador as $fichero) {
            if (! $fichero->isFile() || ! in_array($fichero->getExtension(), ['php', 'js'], true)) {
                continue;
            }

            // La propia definicion no cuenta: si un interruptor solo aparece ahi, no lo lee nadie.
            if (str_ends_with($fichero->getPathname(), 'PosProfile.php')) {
                continue;
            }

            $fuentes[] = (string) file_get_contents($fichero->getPathname());
        }
    }

    $codigo = implode('
', $fuentes);
    $sinUsar = array_values(array_filter(
        PosProfile::optionKeys(),
        static fn (string $opcion): bool => ! str_contains($codigo, "'".$opcion."'"),
    ));

    expect($sinUsar)->toBe([], 'Interruptores que no lee nadie: '.implode(', ', $sinUsar));
});
