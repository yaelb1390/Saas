<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\RoleProvisioner;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Domain\SetupStatus;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 0 de Facturación Electrónica: cimientos.
 *
 * Lo que se comprueba aquí es lo que, si falla, falla en silencio: que los esquemas oficiales sean
 * los de la DGII y no unos retocados, que la configuración de una empresa no se mezcle con la de
 * otra, que la credencial del proveedor no quede en claro, y que la pantalla no se caiga cuando el
 * código llega a producción antes que la migración.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado La Esquina'));
    $this->company->forceFill([
        'tax_id' => '131-00000-1', 'legal_name' => 'Colmado La Esquina SRL', 'modules' => null,
    ])->save();
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@colmado.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->staff = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@colmado.test', 'password' => 'secret-password',
    ]), 'staff');
});

it('el dueño ve el resumen: no configurado, en pruebas y con el aviso de que no es una autorización', function (): void {
    $this->actingAs($this->owner)->get(route('panel.e-invoicing'))
        ->assertOk()
        ->assertSee('Esto no es una autorización de la DGII.')
        ->assertSee('No configurado')
        ->assertSee('Nada de lo que se emita aquí tiene validez fiscal.')
        ->assertSee('E31')
        ->assertSee('Factura de Crédito Fiscal Electrónica');
});

it('el cajero no entra', function (): void {
    $this->actingAs($this->staff)->get(route('panel.e-invoicing'))->assertForbidden();
});

it('sin el módulo contratado no hay pantalla', function (): void {
    $this->company->forceFill(['modules' => ['billing']])->save();

    $this->actingAs($this->owner)->get(route('panel.e-invoicing'))->assertForbidden();
});

it('si la migración aún no se aplicó, la pantalla abre y lo dice en vez de dar un 500', function (): void {
    Schema::drop('electronic_invoicing_settings');
    DbTable::olvidar();

    $this->actingAs($this->owner)->get(route('panel.e-invoicing'))
        ->assertOk()
        ->assertSee('todavía no tiene las tablas de facturación electrónica');
});

it('la configuración nace en pruebas, sin configurar, con el proveedor de prueba y el RNC de «Mi empresa»', function (): void {
    $ajustes = ElectronicInvoicingSettings::paraEmpresa($this->company);

    expect($ajustes->environment)->toBe(Environment::Pruebas)
        ->and($ajustes->status)->toBe(SetupStatus::NoConfigurado)
        ->and($ajustes->provider)->toBe('fake')
        ->and($ajustes->tax_id)->toBe('131000001')
        ->and($ajustes->legal_name)->toBe('Colmado La Esquina SRL');
});

it('un RNC de «Mi empresa» que no parece RNC ni cédula no se precarga', function (): void {
    $this->company->forceFill(['tax_id' => 'pendiente'])->save();

    expect(ElectronicInvoicingSettings::paraEmpresa($this->company)->tax_id)->toBeNull();
});

it('cada empresa tiene su propia configuración y no ve la de otra', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ferretería'));

    $mia = ElectronicInvoicingSettings::paraEmpresa($this->company);
    $suya = ElectronicInvoicingSettings::paraEmpresa($otra);

    expect($mia->id)->not->toBe($suya->id)
        ->and(ElectronicInvoicingSettings::paraEmpresa($this->company)->id)->toBe($mia->id);

    // Con la empresa activa fijada, el scope de tenant esconde la fila de la otra.
    app(CurrentCompany::class)->set($this->company->id);
    expect(ElectronicInvoicingSettings::query()->pluck('id')->all())->toBe([$mia->id]);
});

it('las credenciales del proveedor se guardan cifradas y no van a la auditoría', function (): void {
    $ajustes = ElectronicInvoicingSettings::paraEmpresa($this->company);
    $ajustes->forceFill(['provider_config' => ['api_key' => 'clave-secreta-del-proveedor']])->save();

    $crudo = DB::table('electronic_invoicing_settings')->where('id', $ajustes->id)->value('provider_config');

    expect($crudo)->not->toContain('clave-secreta-del-proveedor')
        ->and($ajustes->fresh()->provider_config['api_key'])->toBe('clave-secreta-del-proveedor');

    $auditado = DB::table('audits')->where('auditable_type', ElectronicInvoicingSettings::class)->pluck('new_values')->implode(' ');
    expect($auditado)->not->toContain('clave-secreta-del-proveedor');
});

it('los quince esquemas oficiales están en el repositorio y sin retocar', function (): void {
    $registro = new SchemaRegistry;

    expect($registro->integrity())->toHaveCount(15)
        ->and($registro->allIntact())->toBeTrue();

    foreach (EcfType::cases() as $tipo) {
        expect(is_file($registro->pathForType($tipo)))->toBeTrue("Falta el XSD de {$tipo->prefix()}")
            ->and($registro->schemaDate($tipo))->not->toBeNull();
    }
});

it('detecta un esquema retocado a mano', function (): void {
    $copia = storage_path('framework/testing/ecf-esquemas');
    File::deleteDirectory($copia);
    File::copyDirectory(config('ecf.spec.path'), $copia);
    File::append($copia.'/ecf-31.xsd', "\n<!-- retocado -->");

    config(['ecf.spec.path' => $copia, 'ecf.spec.manifest' => $copia.'/manifest.json']);

    $registro = new SchemaRegistry;

    expect($registro->allIntact())->toBeFalse()
        ->and($registro->integrity()['ecf-31.xsd']['estado'])->toBe('alterado')
        ->and($registro->integrity()['ecf-32.xsd']['estado'])->toBe('ok');

    File::deleteDirectory($copia);
});

it('los esquemas oficiales se leen sin pedir nada a la red', function (): void {
    $registro = new SchemaRegistry;

    foreach (array_keys($registro->integrity()) as $archivo) {
        $doc = new DOMDocument;
        expect($doc->load($registro->path($archivo), LIBXML_NONET))->toBeTrue("No se pudo leer {$archivo}");
    }
});

it('los permisos ecf.* son del dueño y del administrador, no del cajero', function (): void {
    $ecf = array_values(array_filter(RoleProvisioner::PERMISSIONS, fn (string $p): bool => str_starts_with($p, 'ecf.')));

    expect($ecf)->toHaveCount(9);

    foreach ($ecf as $permiso) {
        expect(RoleProvisioner::ROLES['admin'])->toContain($permiso)
            ->and(RoleProvisioner::ROLES['staff'])->not->toContain($permiso);
    }
});

it('la migración da el módulo solo a quien ya tiene Facturación', function (): void {
    $conFacturacion = DB::table('plans')->insertGetId([
        'name' => 'Con facturación', 'slug' => 'con-fact', 'price' => 1, 'billing_cycle' => 'monthly',
        'modules' => json_encode(['pos', 'billing']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $sinFacturacion = DB::table('plans')->insertGetId([
        'name' => 'Sin facturación', 'slug' => 'sin-fact', 'price' => 1, 'billing_cycle' => 'monthly',
        'modules' => json_encode(['pos']), 'created_at' => now(), 'updated_at' => now(),
    ]);

    (require database_path('migrations/2026_10_02_100100_grant_e_invoicing_module.php'))->up();

    expect(json_decode(DB::table('plans')->where('id', $conFacturacion)->value('modules'), true))->toContain('e_invoicing')
        ->and(json_decode(DB::table('plans')->where('id', $sinFacturacion)->value('modules'), true))->not->toContain('e_invoicing');
});
