<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Application\ElectronicInvoiceService;
use App\Modules\ElectronicInvoicing\Application\PsfeConnectionService;
use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfLine;
use App\Modules\ElectronicInvoicing\Domain\EcfParty;
use App\Modules\ElectronicInvoicing\Domain\EcfReference;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Events\PsfeFailover;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use App\Modules\ElectronicInvoicing\Providers\Psfe\ConnectionCheck;
use App\Modules\ElectronicInvoicing\Providers\Psfe\Digifact\DigifactNucMapper;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeCapabilities;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeDriver;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeField;
use App\Modules\ElectronicInvoicing\Providers\ProviderOutcome;
use App\Modules\ElectronicInvoicing\Providers\ProviderResult;
use App\Modules\ElectronicInvoicing\Providers\PsfeProvider;
use App\Modules\ElectronicInvoicing\Providers\ReceiverLookup;
use App\Modules\ElectronicInvoicing\Signature\SignedXml;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Digifact (primer proveedor certificado real) y el RESPALDO entre varios proveedores.
 *
 * Nunca se llama a Digifact de verdad: `preventStrayRequests` y un fake por prueba (el primer fake
 * que casa gana, así que cada prueba define el suyo). Lo que se vigila:
 *   · el NUC tiene la forma de los ejemplos oficiales de Digifact (tests/Fixtures/digifact);
 *   · con Digifact BMIA no firma: guarda el XML firmado que devuelve y su código de seguridad;
 *   · si el principal no puede, la factura sale por el respaldo… pero NUNCA dos veces: si pudo
 *     recibirla, se le pregunta antes; si no se puede preguntar, queda pendiente.
 */

uses(RefreshDatabase::class);

/** Un proveedor de respaldo de prueba: firma y envía en una llamada y apunta lo que se le pide. */
final class EcfPruebaRespaldoDriver implements PsfeDriver
{
    /** @var list<string> */
    public static array $llamadas = [];

    public static string $consulta = 'accepted';

    public function slug(): string { return 'respaldo'; }

    public function label(): string { return 'Respaldo de prueba'; }

    public function description(): string { return 'Solo para pruebas.'; }

    public function fields(): array { return [new PsfeField('clave', 'Clave', secret: true)]; }

    public function capabilities(): PsfeCapabilities { return new PsfeCapabilities(signs: true, submitsUnsigned: true); }

    public function availableIn(Environment $env): bool { return true; }

    public function testConnection(Company $company, array $credentials, Environment $env): ConnectionCheck { return new ConnectionCheck(true, 'ok', 'Cuenta respaldo'); }

    public function sign(Company $company, array $credentials, Environment $env, DOMDocument $unsigned, bool $writeSignatureDate): SignedXml { throw new LogicException('no'); }

    public function send(Company $company, array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        self::$llamadas[] = 'send';

        return new ProviderResult(ProviderOutcome::Received, trackId: 'R-1', delivered: true, signedXml: '<ECF><Signature><SignatureValue>RSPLD0abcdef</SignatureValue></Signature></ECF>');
    }

    public function sendSummary(Company $company, array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult { return $this->send($company, $credentials, $env, $signedXml, $fileName); }

    public function queryResult(Company $company, array $credentials, Environment $env, string $trackId): ProviderResult
    {
        self::$llamadas[] = 'query:'.$trackId;

        return new ProviderResult(ProviderOutcome::from(self::$consulta), trackId: $trackId);
    }

    public function sendCommercialApproval(Company $company, array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult { return new ProviderResult(ProviderOutcome::NotConfigured); }

    public function voidRange(Company $company, array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult { return new ProviderResult(ProviderOutcome::NotConfigured); }

    public function findReceiver(Company $company, array $credentials, Environment $env, string $taxId): ReceiverLookup { return new ReceiverLookup(ReceiverLookup::UNSUPPORTED); }
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Cache::flush();
    config(['filesystems.fiscal_documents' => 'local', 'ecf_psfe.drivers.respaldo' => EcfPruebaRespaldoDriver::class]);
    Storage::fake('local');
    EcfPruebaRespaldoDriver::$llamadas = [];
    EcfPruebaRespaldoDriver::$consulta = 'accepted';

    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->ajustes = ElectronicInvoicingSettings::paraEmpresa($this->company);
    $this->ajustes->forceFill(['tax_id' => '131000002', 'legal_name' => 'Colmado SRL', 'address' => 'Calle 1'])->save();

    // Conexiones escritas directamente (conectar de verdad prueba contra el proveedor).
    $this->conectar = function (string ...$slugs): void {
        $datos = ['digifact' => ['usuario' => 'colmado', 'clave' => 'secreta'], 'respaldo' => ['clave' => 'x']];
        $this->ajustes->forceFill(['provider' => 'psfe', 'provider_config' => ['connections' => array_map(
            fn (string $s): array => ['psfe' => $s, 'credentials' => $datos[$s], 'check_ok' => true],
            $slugs,
        )]])->save();
    };

    $this->secuencia = fn (EcfType $tipo) => ElectronicNcfSequence::create([
        'company_id' => $this->company->id, 'environment' => Environment::Pruebas, 'ecf_type' => $tipo,
        'range_from' => 1, 'range_to' => 50, 'next_number' => 1, 'is_active' => true,
        'expires_at' => Carbon::create(2027, 12, 31),
    ]);

    $this->documento = fn (EcfType $tipo, array $extra = []) => new EcfDocument(...array_merge([
        'type' => $tipo,
        'encf' => $tipo->prefix().'0000000000',
        'issueDate' => Carbon::create(2026, 10, 5),
        'emitter' => new EcfParty(taxId: '131000002', legalName: 'Colmado SRL', address: 'Calle 1'),
        'lines' => [new EcfLine('Refresco', '2', '118.00', BillingIndicator::Itbis1)],
        'buyer' => $tipo === EcfType::CreditoFiscal ? new EcfParty(taxId: '101000007', legalName: 'Cliente SRL') : null,
    ], $extra));

    $this->emitir = fn (EcfType $tipo, array $extra = []) => app(ElectronicInvoiceService::class)->issue($this->company, ($this->documento)($tipo, $extra));

    // Respuestas de Digifact. `$certificar` decide qué devuelve la certificación.
    $this->firmadoDigifact = '<ECF><Signature><SignatureValue>DGFCTabcdef0123</SignatureValue></Signature></ECF>';
    $this->digifact = function (callable|array|null $certificar = null, array $resultado = [], array $dteInfo = []) {
        Http::fake(function (Request $r) use ($certificar, $resultado, $dteInfo) {
            $url = $r->url();

            if (str_contains($url, '/login/get_token')) {
                return Http::response(['Token' => 'tok-1', 'expira_en' => now()->addDays(30)->toIso8601String(), 'otorgado_a' => 'colmado']);
            }

            if (str_contains($url, '/v2/transform/nuc_json')) {
                if (is_callable($certificar)) {
                    return $certificar($r);
                }

                return Http::response($certificar ?? ['code' => 1, 'message' => 'Documento certificado exitosamente!', 'batch' => digifactEncfEnviado($r), 'authNumber' => 'guid-1', 'responseData1' => base64_encode($this->firmadoDigifact)]);
            }

            if (str_contains($url, 'SHARED_GETRESULTADOENVIO')) {
                return Http::response(['REQUEST_DATA' => [['Respuesta' => 1]], 'RESPONSE' => $resultado]);
            }

            if (str_contains($url, 'SHARED_GETDTEINFO')) {
                return Http::response(['REQUEST_DATA' => [['Respuesta' => 1]], 'RESPONSE' => $dteInfo]);
            }

            if (str_contains($url, 'SHARED_GETINFORTAXID')) {
                return Http::response(['RESPONSE' => [['NAME' => 'COLMADO SRL', 'STATUS' => 'ACTIVO']]]);
            }

            return Http::response('no simulado', 599);
        });
    };
});

/** El e-NCF que BMIA mandó en el NUC (tipo + Secuencia): lo que Digifact certificaría. */
function digifactEncfEnviado(Request $r): string
{
    $nuc = json_decode($r->body(), true);

    return 'E'.$nuc['Header']['DocType'].$nuc['Header']['AdditionalIssueDocInfo'][0]['Value'];
}

/** Las claves de un arreglo, en orden, para comparar formas. */
function digifactClaves(array $a): array
{
    return array_keys($a);
}

it('el NUC de una factura de consumo tiene la forma del ejemplo oficial de Digifact', function (): void {
    ($this->conectar)('digifact');
    ($this->secuencia)(EcfType::Consumo);
    ($this->digifact)();

    $ecf = ($this->emitir)(EcfType::Consumo);
    $nuc = app(DigifactNucMapper::class)->fromDgiiXml(Storage::disk('local')->get($ecf->file('original')->path));
    $ejemplo = json_decode(file_get_contents(base_path('tests/Fixtures/digifact/NUC-32.json')), true);

    // Mismas secciones, con sus erratas (AdditionlInfo, AditionalData) tal como las documenta Digifact.
    expect(digifactClaves($nuc))->toBe(digifactClaves($ejemplo))
        ->and($nuc['CountryCode'])->toBe('DO')
        ->and($nuc['Header']['DocType'])->toBe('32')
        ->and(array_column($nuc['Header']['AdditionalIssueDocInfo'], 'Value', 'Name'))->toMatchArray(['Secuencia' => '0000000001'])
        ->and($nuc['Seller'])->toHaveKeys(['TaxID', 'Name', 'AdditionlInfo', 'BranchInfo'])
        ->and($nuc['Buyer'])->toBe(['TaxID' => '', 'Name' => 'Consumidor Final'])
        ->and($nuc['Items'][0])->toHaveKeys(['Type', 'Description', 'Qty', 'Price', 'Totals', 'AdditionalInfo'])
        ->and(array_column($nuc['Items'][0]['AdditionalInfo'], 'Value', 'Name')['IndicadorFacturacion'])->toBe('1')
        ->and($nuc['Totals']['TotalTaxes']['TotalTax'][0])->toMatchArray(['Code' => 'ITBIS1', 'Rate' => '18.00'])
        ->and($nuc['Totals']['GrandTotal']['InvoiceTotal'])->toBe('236.00')
        ->and($nuc['AdditionalDocumentInfo']['AdditionalInfo'][0])->toBe(['AditionalData' => null, 'AditionalInfo' => null])
        // Cada dato adicional con la forma {Name, Data, Value} de sus ejemplos.
        ->and(array_keys($nuc['Header']['AdditionalIssueDocInfo'][0]))->toBe(array_keys($ejemplo['Header']['AdditionalIssueDocInfo'][0]));
});

it('una nota de crédito lleva el comprobante modificado como en el ejemplo 34', function (): void {
    ($this->conectar)('digifact');
    ($this->secuencia)(EcfType::NotaCredito);
    ($this->digifact)(['code' => 1, 'batch' => 'E340000000001', 'responseData1' => base64_encode('<x/>')]);

    $ecf = ($this->emitir)(EcfType::NotaCredito, [
        'reference' => new EcfReference('E310000000009', Carbon::create(2026, 10, 1), 3, 'Corrección de monto'),
    ]);
    $nuc = app(DigifactNucMapper::class)->fromDgiiXml(Storage::disk('local')->get($ecf->file('original')->path));
    $ejemplo = json_decode(file_get_contents(base_path('tests/Fixtures/digifact/NUC-34.json')), true);
    $ref = $nuc['AdditionalDocumentInfo']['AdditionalInfo'][0]['AditionalData']['Data'][0];

    expect($ref['Name'])->toBe('INFORMACION_REFERENCIA')
        ->and(array_column($ref['Info'], 'Name'))->toBe(array_column($ejemplo['AdditionalDocumentInfo']['AdditionalInfo'][0]['AditionalData']['Data'][0]['Info'], 'Name'))
        ->and(array_column($ref['Info'], 'Value', 'Name'))->toMatchArray(['NCFModificado' => 'E310000000009', 'FechaNCFModificado' => '2026-10-01', 'CodigoModificacion' => '3']);
});

it('con Digifact BMIA no firma: envía, guarda el firmado que devuelve y su código, y lo consulta después', function (): void {
    ($this->conectar)('digifact');
    ($this->secuencia)(EcfType::CreditoFiscal);
    ($this->digifact)(null, [['Codigo' => '1', 'Estado' => 'Aceptado', 'Mensajes' => '0 - |', 'ENCF' => 'E310000000001', 'SecuenciaUtilizada' => 'True']]);

    $ecf = ($this->emitir)(EcfType::CreditoFiscal);

    expect($ecf->status)->toBe(EcfStatus::Recibido)
        ->and($ecf->provider)->toBe('psfe:digifact')
        ->and($ecf->track_id)->toBe('E310000000001')
        ->and($ecf->security_code)->toBe('DGFCTa')
        ->and(Storage::disk('local')->get($ecf->file('firmado')->path))->toBe($this->firmadoDigifact)
        // Pasó de «XML generado» a «pendiente» sin que BMIA firmara.
        ->and($ecf->auditLogs()->whereNotNull('to_status')->orderBy('id')->pluck('to_status')->all())
        ->toBe(['generado', 'xml_generado', 'pendiente_envio', 'enviando', 'recibido']);

    // El token se pidió una vez y viaja en Authorization; el número va en «Secuencia».
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'nuc_json') && $r->hasHeader('Authorization', 'tok-1')
        && str_contains($r->url(), 'TAXID=131000002') && $r['Header']['AdditionalIssueDocInfo'][0]['Value'] === '0000000001');

    $this->travel(2)->minutes();
    app(ElectronicInvoiceService::class)->processPending();

    expect($ecf->fresh()->status)->toBe(EcfStatus::Aceptado);
});

it('el token se pide una vez, se guarda cifrado y se renueva si Digifact lo rechaza', function (): void {
    ($this->conectar)('digifact');
    ($this->secuencia)(EcfType::CreditoFiscal);
    $certificaciones = 0;
    ($this->digifact)(function (Request $r) use (&$certificaciones) {
        $certificaciones++;

        // La segunda certificación encuentra el token vencido: 401 y se pide otro.
        return $certificaciones === 2
            ? Http::response(['message' => 'token vencido'], 401)
            : Http::response(['code' => 1, 'batch' => digifactEncfEnviado($r), 'responseData1' => base64_encode('<x/>')]);
    });

    ($this->emitir)(EcfType::CreditoFiscal);
    $clave = "ecf:digifact-token:{$this->company->id}:pruebas:".hash('sha256', 'colmado|secreta');
    $guardado = Cache::get($clave);
    ($this->emitir)(EcfType::CreditoFiscal);

    $tokens = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'get_token'))->count();

    // En caché va cifrado, nunca el token en claro.
    expect($guardado)->not->toBe('tok-1')
        ->and(Crypt::decryptString($guardado))->toBe('tok-1')
        // 1 al empezar + 1 al renovar tras el 401; las dos facturas salieron.
        ->and($tokens)->toBe(2)
        ->and(ElectronicInvoice::query()->where('status', EcfStatus::Recibido->value)->count())->toBe(2);
});

it('si la DGII rechaza con la secuencia sin usar, el número vuelve a estar disponible', function (): void {
    ($this->conectar)('digifact');
    ($this->secuencia)(EcfType::CreditoFiscal);
    ($this->digifact)(null, [['Codigo' => '2', 'Estado' => 'Rechazado', 'Mensajes' => 'RNC del comprador inválido', 'ENCF' => 'E310000000001', 'SecuenciaUtilizada' => 'False']]);

    $ecf = ($this->emitir)(EcfType::CreditoFiscal);
    $this->travel(2)->minutes();
    app(ElectronicInvoiceService::class)->processPending();

    expect($ecf->fresh()->status)->toBe(EcfStatus::Rechazado)
        ->and($ecf->fresh()->last_error)->toContain('RNC del comprador inválido')
        ->and(App\Modules\ElectronicInvoicing\Models\ElectronicNcfRelease::query()->where('number', 1)->exists())->toBeTrue();
});

it('un error en los datos queda en error con el motivo de Digifact y NO prueba con el respaldo', function (): void {
    ($this->conectar)('digifact', 'respaldo');
    ($this->secuencia)(EcfType::CreditoFiscal);
    ($this->digifact)(['code' => 0, 'message' => 'Error de validación', 'infoDetails' => [['code' => 'V12', 'message' => 'El RNC del comprador no existe']]]);

    $ecf = ($this->emitir)(EcfType::CreditoFiscal);

    expect($ecf->status)->toBe(EcfStatus::Error)
        ->and($ecf->last_error)->toContain('El RNC del comprador no existe')
        ->and(EcfPruebaRespaldoDriver::$llamadas)->toBe([]);
});

it('Digifact caído (no se pudo conectar): la factura sale por el respaldo y queda apuntado', function (): void {
    Event::fake([PsfeFailover::class]);
    ($this->conectar)('digifact', 'respaldo');
    ($this->secuencia)(EcfType::CreditoFiscal);
    ($this->digifact)(fn () => throw new ConnectionException('cURL error 7: Failed to connect to testnucdo.digifact.com'));

    $ecf = ($this->emitir)(EcfType::CreditoFiscal);

    expect($ecf->status)->toBe(EcfStatus::Recibido)
        ->and($ecf->provider)->toBe('psfe:respaldo')
        ->and(EcfPruebaRespaldoDriver::$llamadas)->toBe(['send'])
        ->and($ecf->security_code)->toBe('RSPLD0')
        ->and($ecf->auditLogs()->pluck('action')->all())->toContain('Enviado por un proveedor de respaldo');

    Event::assertDispatched(PsfeFailover::class, fn (PsfeFailover $e) => $e->failedProviders === ['digifact'] && $e->usedProvider === 'respaldo');

    // Digifact queda en pausa: la siguiente factura ni lo intenta.
    $antes = count(Http::recorded());
    ($this->emitir)(EcfType::CreditoFiscal);

    expect(count(Http::recorded()))->toBe($antes)
        ->and(EcfPruebaRespaldoDriver::$llamadas)->toBe(['send', 'send']);
});

it('tiempo agotado pero Digifact lo tenía: no se envía por el respaldo', function (): void {
    ($this->conectar)('digifact', 'respaldo');
    ($this->secuencia)(EcfType::CreditoFiscal);
    ($this->digifact)(fn () => throw new ConnectionException('cURL error 28: Operation timed out'), [], [['DocumentGUID' => 'guid-1', 'NumeroSecuencia' => 'E310000000001']]);

    $ecf = ($this->emitir)(EcfType::CreditoFiscal);

    expect(EcfPruebaRespaldoDriver::$llamadas)->toBe([])
        ->and($ecf->status)->toBe(EcfStatus::Recibido)
        ->and($ecf->provider)->toBe('psfe:digifact');
});

it('tiempo agotado y no se puede preguntar: queda pendiente en vez de arriesgar un doble envío', function (): void {
    ($this->conectar)('digifact', 'respaldo');
    ($this->secuencia)(EcfType::CreditoFiscal);
    Http::fake(function (Request $r) {
        return str_contains($r->url(), 'get_token')
            ? Http::response(['Token' => 'tok-1', 'expira_en' => now()->addDays(30)->toIso8601String()])
            : throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $ecf = ($this->emitir)(EcfType::CreditoFiscal);

    expect(EcfPruebaRespaldoDriver::$llamadas)->toBe([])
        ->and($ecf->status)->toBe(EcfStatus::PendienteEnvio)
        // Se recuerda quién pudo tenerlo: el próximo intento le pregunta a él primero.
        ->and($ecf->provider)->toBe('psfe:digifact');
});

it('en el siguiente intento se le pregunta primero a quien pudo tenerlo, y si no lo tiene sale por el respaldo', function (): void {
    ($this->conectar)('digifact', 'respaldo');
    ($this->secuencia)(EcfType::CreditoFiscal);
    $caido = true;
    Http::fake(function (Request $r) use (&$caido) {
        if (str_contains($r->url(), 'get_token')) {
            return Http::response(['Token' => 'tok-1', 'expira_en' => now()->addDays(30)->toIso8601String()]);
        }

        if ($caido) {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }

        // Ya responde, y no tiene el documento.
        return Http::response(['REQUEST_DATA' => [['Respuesta' => 1]], 'RESPONSE' => []]);
    });

    $ecf = ($this->emitir)(EcfType::CreditoFiscal);
    expect($ecf->status)->toBe(EcfStatus::PendienteEnvio)->and(EcfPruebaRespaldoDriver::$llamadas)->toBe([]);

    $caido = false;
    $this->travel(2)->minutes();
    app(ElectronicInvoiceService::class)->processPending();

    // Primero se le preguntó a Digifact (que no lo tenía) y, como sigue en pausa, salió por el respaldo.
    expect(collect(Http::recorded())->contains(fn ($p) => str_contains($p[0]->url(), 'SHARED_GETRESULTADOENVIO')))->toBeTrue()
        ->and(EcfPruebaRespaldoDriver::$llamadas)->toBe(['send'])
        ->and($ecf->fresh()->provider)->toBe('psfe:respaldo')
        ->and($ecf->fresh()->status)->toBe(EcfStatus::Recibido);
});

it('la consulta va al proveedor que recibió el documento', function (): void {
    ($this->conectar)('digifact', 'respaldo');
    ($this->secuencia)(EcfType::CreditoFiscal);
    ($this->digifact)(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

    $ecf = ($this->emitir)(EcfType::CreditoFiscal);
    $this->travel(2)->minutes();
    app(ElectronicInvoiceService::class)->processPending();

    expect(EcfPruebaRespaldoDriver::$llamadas)->toBe(['send', 'query:R-1'])
        ->and($ecf->fresh()->status)->toBe(EcfStatus::Aceptado);
});

it('subir un proveedor cambia por cuál sale primero', function (): void {
    ($this->conectar)('digifact', 'respaldo');
    ($this->secuencia)(EcfType::CreditoFiscal);
    ($this->digifact)();

    app(PsfeConnectionService::class)->raise($this->company, 'respaldo');
    $ecf = ($this->emitir)(EcfType::CreditoFiscal);

    expect($ecf->provider)->toBe('psfe:respaldo')
        ->and(collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'nuc_json'))->count())->toBe(0);
});

it('una conexión guardada con la forma antigua (un solo proveedor) sigue funcionando', function (): void {
    $this->ajustes->forceFill(['provider' => 'psfe', 'provider_config' => ['psfe' => 'sandbox', 'credentials' => ['api_key' => 'sandbox_x'], 'check_ok' => true]])->save();

    expect(app(PsfeProvider::class)->isConfigured($this->company))->toBeTrue()
        ->and(app(PsfeConnectionService::class)->current($this->company)[0]['slug'])->toBe('sandbox');
});

it('conectar Digifact con usuario o contraseña malos no guarda nada', function (): void {
    Http::fake(['*/login/get_token' => Http::response(['message' => 'Credenciales inválidas'], 401)]);

    $prueba = app(PsfeConnectionService::class)->connect($this->company, 'digifact', ['usuario' => 'colmado', 'clave' => 'mala']);

    expect($prueba->ok)->toBeFalse()
        ->and($prueba->message)->toContain('rechazó el usuario o la contraseña')
        ->and($this->ajustes->fresh()->provider_config)->toBeNull();
});

it('conectar Digifact con datos buenos guarda la cuenta como principal y añade otro como respaldo', function (): void {
    ($this->digifact)();

    app(PsfeConnectionService::class)->connect($this->company, 'digifact', ['usuario' => 'colmado', 'clave' => 'buena']);
    app(PsfeConnectionService::class)->connect($this->company, 'respaldo', ['clave' => 'x']);

    $lista = app(PsfeConnectionService::class)->current($this->company);

    expect(array_column($lista, 'slug'))->toBe(['digifact', 'respaldo'])
        ->and($lista[0]['account'])->toBe('COLMADO SRL');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get_token') && $r['Username'] === 'DO.131000002.colmado');
});
