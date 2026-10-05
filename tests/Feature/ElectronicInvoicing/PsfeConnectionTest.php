<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Application\Diagnostics;
use App\Modules\ElectronicInvoicing\Application\ElectronicInvoiceService;
use App\Modules\ElectronicInvoicing\Application\PsfeConnectionService;
use App\Modules\ElectronicInvoicing\Application\SetupWizard;
use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfLine;
use App\Modules\ElectronicInvoicing\Domain\EcfParty;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\EmissionMode;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Events\PsfeConnected;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use App\Modules\ElectronicInvoicing\Providers\ProviderOutcome;
use App\Modules\ElectronicInvoicing\Providers\PsfeProvider;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * «Conecta tu proveedor autorizado»: conectar un PSFE con los datos de la cuenta y, si firma por la
 * empresa, emitir sin subir certificado.
 *
 * Se prueba con el conector simulado (`sandbox`), el único del catálogo hasta tener el de un
 * proveedor real. Lo que se vigila:
 *   · una clave mala no deja nada guardado ni cambia de proveedor;
 *   · la clave nunca sale: ni en la base de datos en claro, ni en la auditoría, ni en la pantalla;
 *   · con un proveedor que firma, el e-CF sale firmado sin certificado de la empresa;
 *   · desconectar no deja la emisión encendida sin con qué firmar.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['filesystems.fiscal_documents' => 'local']);
    Storage::fake('local');

    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado'));
    $this->company->forceFill(['modules' => null])->save();
    app(CurrentCompany::class)->set($this->company->id);

    $this->ajustes = ElectronicInvoicingSettings::paraEmpresa($this->company);
    $this->ajustes->forceFill(['tax_id' => '131000002', 'legal_name' => 'Colmado SRL', 'address' => 'Calle 1'])->save();

    $this->duena = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@psfe.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->conectar = fn (string $clave) => $this->actingAs($this->duena)
        ->post(route('panel.e-invoicing.psfe.connect'), ['psfe' => 'sandbox', 'credentials' => ['api_key' => $clave]]);

    $this->emitirConsumo = function () {
        ElectronicNcfSequence::create([
            'company_id' => $this->company->id, 'environment' => Environment::Pruebas, 'ecf_type' => EcfType::Consumo,
            'range_from' => 1, 'range_to' => 50, 'next_number' => 1, 'is_active' => true,
            'expires_at' => Carbon::create(2027, 12, 31),
        ]);

        return app(ElectronicInvoiceService::class)->issue($this->company, new EcfDocument(
            type: EcfType::Consumo,
            encf: 'E320000000000',
            issueDate: Carbon::create(2026, 10, 4),
            emitter: new EcfParty(taxId: '131000002', legalName: 'Colmado SRL', address: 'Calle 1'),
            lines: [new EcfLine('Refresco', '2', '118.00', BillingIndicator::Itbis1)],
        ));
    };
});

it('sin conectar, el PSFE sigue diciendo «no configurado» como antes', function (): void {
    $this->ajustes->forceFill(['provider' => 'psfe'])->save();

    $r = app(PsfeProvider::class)->send($this->company, Environment::Pruebas, '<x/>', 'x.xml');

    expect($r->outcome)->toBe(ProviderOutcome::NotConfigured)
        ->and(app(PsfeProvider::class)->isConfigured($this->company))->toBeFalse();
});

it('con una clave buena conecta, cifra la clave y no la deja ni en la auditoría ni en la pantalla', function (): void {
    Event::fake([PsfeConnected::class]);

    ($this->conectar)('sandbox_secreto_123')->assertSessionHas('panel_ok');

    $ajustes = $this->ajustes->fresh();
    expect($ajustes->provider)->toBe('psfe')
        ->and($ajustes->provider_config['psfe'])->toBe('sandbox')
        ->and($ajustes->provider_config['credentials']['api_key'])->toBe('sandbox_secreto_123')
        ->and($ajustes->provider_config['check_ok'])->toBeTrue();

    // En la base de datos va cifrada; en la auditoría, ni cifrada.
    expect((string) DB::table('electronic_invoicing_settings')->where('id', $ajustes->id)->value('provider_config'))
        ->not->toContain('sandbox_secreto_123')
        ->and(DB::table('audits')->get()->toJson())->not->toContain('sandbox_secreto_123');

    Event::assertDispatched(PsfeConnected::class, fn (PsfeConnected $e) => $e->psfe === 'sandbox' && $e->companyId === $this->company->id);

    $this->actingAs($this->duena)->get(route('panel.e-invoicing'))->assertOk()
        ->assertSee('Conectado a Proveedor simulado (pruebas)')
        ->assertSee('La hace tu proveedor')
        ->assertDontSee('sandbox_secreto_123');
});

it('con una clave mala no guarda nada ni cambia de proveedor, y dice por qué', function (): void {
    ($this->conectar)('clave_mala')->assertSessionHasErrors(['psfe' => 'No se conectó: El proveedor rechazó la clave de API.']);

    $ajustes = $this->ajustes->fresh();
    expect($ajustes->provider)->toBe('fake')
        ->and($ajustes->provider_config)->toBeNull();
});

it('al reconectar, una clave vacía conserva la guardada', function (): void {
    ($this->conectar)('sandbox_original')->assertSessionHas('panel_ok');
    ($this->conectar)('')->assertSessionHas('panel_ok');

    expect($this->ajustes->fresh()->provider_config['credentials']['api_key'])->toBe('sandbox_original');
});

it('el proveedor simulado no se puede conectar en producción', function (): void {
    $this->ajustes->forceFill(['environment' => Environment::Produccion, 'provider' => 'psfe'])->save();

    ($this->conectar)('sandbox_x')->assertSessionHasErrors('psfe');

    expect($this->ajustes->fresh()->provider_config)->toBeNull();
});

it('con un proveedor que firma, emite sin certificado de la empresa y guarda la firma y su código', function (): void {
    app(PsfeConnectionService::class)->connect($this->company, 'sandbox', ['api_key' => 'sandbox_ok']);

    expect(app(CertificateVault::class)->active($this->company))->toBeNull();

    $ecf = ($this->emitirConsumo)();

    expect($ecf->status)->toBe(EcfStatus::Aceptado)
        ->and($ecf->security_code)->toHaveLength(6)
        ->and($ecf->provider)->toBe('psfe')
        ->and($ecf->files()->pluck('kind')->sort()->values()->all())->toBe(['firmado', 'original', 'rfce', 'rfce_firmado']);
});

it('sin proveedor que firme, sigue exigiendo el certificado', function (): void {
    expect(fn () => ($this->emitirConsumo)())
        ->toThrow(App\Modules\ElectronicInvoicing\Signature\CertificateException::class);
});

it('el asistente y el diagnóstico dan la firma por resuelta con el proveedor conectado', function (): void {
    $this->ajustes->forceFill(['provider' => 'psfe'])->save();

    $paso3 = fn () => collect(app(SetupWizard::class)->steps($this->company))->firstWhere('n', 3);
    expect($paso3()['done'])->toBeFalse()
        ->and($paso3()['anchor'])->toBe('proveedor');

    app(PsfeConnectionService::class)->connect($this->company, 'sandbox', ['api_key' => 'sandbox_ok']);

    expect($paso3()['done'])->toBeTrue();

    $chequeos = collect(app(Diagnostics::class)->checks($this->company, probarAlmacenamiento: false))->keyBy('key');
    expect($chequeos['certificado']['level'])->toBe(Diagnostics::OK)
        ->and($chequeos['proveedor']['level'])->toBe(Diagnostics::OK);
});

it('«Probar otra vez» apunta el fallo y el diagnóstico avisa', function (): void {
    app(PsfeConnectionService::class)->connect($this->company, 'sandbox', ['api_key' => 'sandbox_ok']);

    // La cuenta deja de aceptar la clave (la revocaron en el proveedor).
    $ajustes = $this->ajustes->fresh();
    $ajustes->forceFill(['provider_config' => array_merge($ajustes->provider_config, ['credentials' => ['api_key' => 'revocada']])])->save();

    $this->actingAs($this->duena)->post(route('panel.e-invoicing.psfe.test'))->assertSessionHas('panel_error');

    expect($this->ajustes->fresh()->provider_config['check_ok'])->toBeFalse();

    $chequeos = collect(app(Diagnostics::class)->checks($this->company, probarAlmacenamiento: false))->keyBy('key');
    expect($chequeos['proveedor']['level'])->toBe(Diagnostics::AVISO);
});

it('desconectar borra la clave y apaga la emisión si ya no hay con qué firmar', function (): void {
    app(PsfeConnectionService::class)->connect($this->company, 'sandbox', ['api_key' => 'sandbox_ok']);
    $this->ajustes->fresh()->forceFill(['emission_mode' => EmissionMode::Sombra->value])->save();

    $this->actingAs($this->duena)->delete(route('panel.e-invoicing.psfe.disconnect'))
        ->assertSessionHas('panel_ok', 'Proveedor desconectado. La emisión de e-CF quedó apagada: no hay con qué firmar.');

    $ajustes = $this->ajustes->fresh();
    expect($ajustes->provider)->toBe('fake')
        ->and($ajustes->provider_config)->toBeNull()
        ->and($ajustes->emissionMode())->toBe(EmissionMode::Apagado);
});
