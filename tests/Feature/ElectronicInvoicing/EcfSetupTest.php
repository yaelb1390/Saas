<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Domain\EmissionMode;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Domain\SetupStatus;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Xml\TerritoryCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
 * Fase 6a: configuración del emisor (datos fiscales, ambiente, proveedor) y estado de BMIA.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['filesystems.fiscal_documents' => 'local']);
    Storage::fake('local');

    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado'));
    $this->company->forceFill(['modules' => null])->save();
    app(CurrentCompany::class)->set($this->company->id);

    $this->duena = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@setup.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->datos = fn (array $extra = []) => array_merge([
        'tax_id' => '131-00000-2', 'legal_name' => 'Colmado La Esquina SRL', 'trade_name' => 'La Esquina',
        'address' => 'Calle Duarte 45', 'province' => '010000', 'municipality' => '010100',
        'email' => 'facturas@colmado.test', 'environment' => 'pruebas', 'provider' => 'fake',
    ], $extra);

    $this->guardar = fn (array $extra = []) => $this->actingAs($this->duena)
        ->post(route('panel.e-invoicing.settings.update'), ($this->datos)($extra));

    $this->certificado = function (): void {
        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'PRUEBA BMIA', 'countryName' => 'DO'], $llave, ['digest_alg' => 'sha256']);
        openssl_pkcs12_export(openssl_csr_sign($csr, null, $llave, 365, ['digest_alg' => 'sha256']), $p12, $llave, 'clave');
        app(CertificateVault::class)->store($this->company, $p12, 'clave');
    };
});

it('el catálogo de provincias y municipios sale del XSD oficial', function (): void {
    $c = app(TerritoryCatalog::class);

    expect($c->all())->toHaveCount(582)
        ->and($c->provinces())->toHaveCount(32)
        ->and($c->name('010000'))->toBe('Distrito Nacional')
        ->and($c->isMunicipality('010100'))->toBeTrue()
        ->and($c->isProvince('010100'))->toBeFalse();
});

it('guarda los datos fiscales normalizando el RNC', function (): void {
    ($this->guardar)()->assertSessionHas('panel_ok');

    $a = ElectronicInvoicingSettings::paraEmpresa($this->company);
    expect($a->tax_id)->toBe('131000002')
        ->and($a->legal_name)->toBe('Colmado La Esquina SRL')
        ->and($a->municipality)->toBe('010100')
        // Sin certificado todavía.
        ->and($a->status)->toBe(SetupStatus::NoConfigurado);

    $this->actingAs($this->duena)->get(route('panel.e-invoicing'))->assertOk()
        ->assertSee('Datos fiscales y ambiente')->assertSee('Distrito Nacional');
});

it('rechaza un RNC con dígito verificador malo y un municipio de otra provincia', function (): void {
    ($this->guardar)(['tax_id' => '131000003', 'municipality' => '020100'])
        ->assertSessionHasErrors(['tax_id', 'municipality']);
});

it('pasar a producción exige confirmar la autorización y un proveedor real', function (): void {
    ($this->guardar)(['environment' => 'produccion', 'provider' => 'psfe'])->assertSessionHasErrors('confirm_authorized');
    ($this->guardar)(['environment' => 'produccion', 'provider' => 'fake', 'confirm_authorized' => '1'])->assertSessionHasErrors('provider');

    ($this->guardar)(['environment' => 'produccion', 'provider' => 'psfe', 'confirm_authorized' => '1'])->assertSessionHas('panel_ok');
    expect(ElectronicInvoicingSettings::paraEmpresa($this->company)->environment)->toBe(Environment::Produccion);

    // Ya en producción, guardar otros datos no vuelve a pedir la confirmación.
    ($this->guardar)(['environment' => 'produccion', 'provider' => 'psfe', 'address' => 'Otra 1'])->assertSessionHas('panel_ok');
});

it('cambiar de ambiente apaga la emisión y el estado sigue al ambiente', function (): void {
    ($this->certificado)();
    ($this->guardar)()->assertSessionHas('panel_ok');

    $this->actingAs($this->duena)->post(route('panel.e-invoicing.mode.update'), ['emission_mode' => 'sombra']);
    $a = ElectronicInvoicingSettings::paraEmpresa($this->company);
    expect($a->emissionMode())->toBe(EmissionMode::Sombra)->and($a->status)->toBe(SetupStatus::EnPruebas);

    ($this->guardar)(['environment' => 'produccion', 'provider' => 'dgii', 'confirm_authorized' => '1']);
    $a->refresh();
    expect($a->emissionMode())->toBe(EmissionMode::Apagado)->and($a->status)->toBe(SetupStatus::Autorizado);

    $this->actingAs($this->duena)->post(route('panel.e-invoicing.mode.update'), ['emission_mode' => 'real'])->assertSessionHas('panel_ok');
    expect($a->refresh()->status)->toBe(SetupStatus::Activo);
});

it('el cajero no cambia la configuración', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero', 'email' => 'cajero@setup.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->post(route('panel.e-invoicing.settings.update'), ($this->datos)())->assertForbidden();
});
