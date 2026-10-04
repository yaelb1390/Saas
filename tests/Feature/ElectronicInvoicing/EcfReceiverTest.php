<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Application\EcfXmlGenerator;
use App\Modules\ElectronicInvoicing\Application\ElectronicInvoiceService;
use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfLine;
use App\Modules\ElectronicInvoicing\Domain\EcfParty;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use App\Modules\ElectronicInvoicing\Models\ElectronicReceivedDocument;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Signature\XmlSigner;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use App\Modules\ElectronicInvoicing\Xml\SafeXml;
use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use App\Modules\ElectronicInvoicing\Xml\XmlValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
 * Fase 7a: la empresa como RECEPTORA [Descripción Técnica Servicios Emisores Electrónicos].
 *
 * «Nosotros» (RNC 131000002) recibimos de «Proveedor» (RNC 101000007), que firma con su propio
 * certificado autofirmado. Todo por HTTP, como lo haría otro contribuyente.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['filesystems.fiscal_documents' => 'local']);
    Storage::fake('local');

    $p12 = function (Illuminate\Database\Eloquent\Model $empresa, string $cn): void {
        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $cn, 'countryName' => 'DO'], $llave, ['digest_alg' => 'sha256']);
        openssl_pkcs12_export(openssl_csr_sign($csr, null, $llave, 365, ['digest_alg' => 'sha256']), $salida, $llave, 'clave');
        app(CertificateVault::class)->store($empresa, $salida, 'clave');
    };

    app(CurrentCompany::class)->forget();
    $this->proveedor = app(CompanyService::class)->create(new CreateCompanyData(name: 'Proveedor'));
    $p12($this->proveedor, 'PROVEEDOR');

    $this->nosotros = app(CompanyService::class)->create(new CreateCompanyData(name: 'Nosotros'));
    $this->nosotros->forceFill(['modules' => null])->save();
    $p12($this->nosotros, 'NOSOTROS');
    $this->ajustes = ElectronicInvoicingSettings::paraEmpresa($this->nosotros);
    $this->ajustes->forceFill(['tax_id' => '131000002', 'legal_name' => 'Nosotros SRL', 'address' => 'Calle 1'])->save();

    $this->base = '/api/ecf-receptor/'.$this->ajustes->receiverKey();

    // Un e-CF 31 que el proveedor nos emite, firmado con SU certificado.
    $this->ecfDelProveedor = function (string $comprador = '131000002', string $encf = 'E310000000007'): string {
        $doc = new EcfDocument(
            type: EcfType::CreditoFiscal, encf: $encf, issueDate: Carbon::create(2026, 10, 3),
            emitter: new EcfParty(taxId: '101000007', legalName: 'Proveedor SRL', address: 'Av. 2'),
            lines: [new EcfLine('Harina', '10', '118.00', BillingIndicator::Itbis1)],
            buyer: new EcfParty(taxId: $comprador, legalName: 'Nosotros SRL'),
            sequenceExpiresAt: Carbon::create(2027, 12, 31),
        );
        $xml = app(EcfXmlGenerator::class)->generate($doc)->xml;

        return app(XmlSigner::class)->sign($xml, app(CertificateVault::class)->load($this->proveedor), now())->xml;
    };

    // Semilla → firmada por el proveedor → token.
    $this->token = function (): string {
        $semilla = $this->get($this->base.'/fe/autenticacion/api/semilla')->assertOk()->getContent();
        $firmada = app(XmlSigner::class)->sign(SafeXml::load($semilla), app(CertificateVault::class)->load($this->proveedor), now(), writeSignatureDate: false)->xml;

        return $this->post($this->base.'/fe/autenticacion/api/validacioncertificado', [
            'xml' => UploadedFile::fake()->createWithContent('semilla.xml', $firmada),
        ])->assertOk()->json('token');
    };

    $this->enviar = fn (string $xml, ?string $token, string $ruta = '/fe/recepcion/api/ecf') => $this->withHeaders($token ? ['Authorization' => 'Bearer '.$token] : [])
        ->post($this->base.$ruta, ['xml' => UploadedFile::fake()->createWithContent('101000007E310000000007.xml', $xml)]);

    $this->acuse = function (string $xml): array {
        $d = SafeXml::load($xml);
        $x = new DOMXPath($d);

        return [
            'estado' => $x->evaluate('string(//Estado)'),
            'motivo' => $x->evaluate('string(//CodigoMotivoNoRecibido)'),
            'xsd' => app(XmlValidator::class)->validate($d, app(SchemaRegistry::class)->validationPath('arecf.xsd')),
            'firmado' => str_contains($xml, '<SignatureValue>'),
        ];
    };
});

it('autentica con la semilla firmada y entrega el token en JSON o XML', function (): void {
    $token = ($this->token)();
    expect(strlen($token))->toBe(64);

    // Una semilla no se usa dos veces y una inventada no vale.
    $this->post($this->base.'/fe/autenticacion/api/validacioncertificado', [
        'xml' => UploadedFile::fake()->createWithContent('s.xml', '<SemillaModel><valor>inventada</valor></SemillaModel>'),
    ])->assertStatus(400);

    $semilla = $this->get($this->base.'/fe/autenticacion/api/semilla')->getContent();
    $firmada = app(XmlSigner::class)->sign(SafeXml::load($semilla), app(CertificateVault::class)->load($this->proveedor), now(), writeSignatureDate: false)->xml;
    $this->withHeaders(['Accept' => 'application/xml'])->post($this->base.'/fe/autenticacion/api/validacioncertificado', [
        'xml' => UploadedFile::fake()->createWithContent('s.xml', $firmada),
    ])->assertOk()->assertSee('<RespuestaAutenticacion><token>', false);
});

it('recibe un e-CF válido y devuelve el acuse de recibo firmado (Estado 0)', function (): void {
    $r = ($this->enviar)(($this->ecfDelProveedor)(), ($this->token)())->assertOk();
    $a = ($this->acuse)($r->getContent());

    expect($a['estado'])->toBe('0')->and($a['motivo'])->toBe('')->and($a['firmado'])->toBeTrue()->and($a['xsd'])->toBe([]);

    $doc = ElectronicReceivedDocument::query()->withoutGlobalScopes()->sole();
    expect($doc->emitter_tax_id)->toBe('101000007')
        ->and($doc->e_ncf)->toBe('E310000000007')
        ->and($doc->ecf_type)->toBe(31)
        ->and((string) $doc->total)->toBe('1180.00')
        ->and($doc->receipt_status)->toBe(0)
        ->and(Storage::disk('local')->get($doc->xml_path))->toContain('E310000000007')
        ->and(hash('sha256', Storage::disk('local')->get($doc->arecf_path)))->toBe($doc->arecf_sha256);
});

it('sin token no recibe (la autenticación está declarada)', function (): void {
    ($this->enviar)(($this->ecfDelProveedor)(), null)->assertStatus(401);
    ($this->enviar)(($this->ecfDelProveedor)(), 'token-falso')->assertStatus(401);
});

it('no recibe con su motivo: duplicado, otro comprador, firma alterada y XML inválido', function (): void {
    $token = ($this->token)();
    $ecf = ($this->ecfDelProveedor)();

    ($this->enviar)($ecf, $token);
    $dup = ($this->acuse)(($this->enviar)($ecf, $token)->getContent());
    $otro = ($this->acuse)(($this->enviar)(($this->ecfDelProveedor)('401000008', 'E310000000008'), $token)->getContent());
    $alterado = ($this->acuse)(($this->enviar)(str_replace('<MontoTotal>1180.00</MontoTotal>', '<MontoTotal>11.80</MontoTotal>', ($this->ecfDelProveedor)('131000002', 'E310000000009')), $token)->getContent());
    $basura = ($this->acuse)(($this->enviar)('<ECF><Nada/></ECF>', $token)->getContent());

    expect([$dup['estado'], $dup['motivo']])->toBe(['1', '3'])
        ->and([$otro['estado'], $otro['motivo']])->toBe(['1', '4'])
        ->and([$alterado['estado'], $alterado['motivo']])->toBe(['1', '2'])
        ->and([$basura['estado'], $basura['motivo']])->toBe(['1', '1'])
        // Todos los acuses, también los de «no recibido», cumplen su XSD.
        ->and($dup['xsd'])->toBe([])->and($basura['xsd'])->toBe([]);
});

it('los recursos no distinguen mayúsculas ni la tilde de «recepción»', function (): void {
    $token = ($this->token)();

    $a = ($this->acuse)(($this->enviar)(($this->ecfDelProveedor)(), $token, '/FE/Recepci%C3%B3n/API/ECF')->assertOk()->getContent());
    expect($a['estado'])->toBe('0');
});

it('una clave que no existe da 404', function (): void {
    $this->get('/api/ecf-receptor/no-existe/fe/autenticacion/api/semilla')->assertNotFound();
});

it('recibe la aprobación comercial que el comprador emite sobre un e-CF propio', function (): void {
    // Nosotros emitimos un 31 al proveedor (proveedor de prueba, ambiente de pruebas).
    app(CurrentCompany::class)->set($this->nosotros->id);
    ElectronicNcfSequence::create([
        'company_id' => $this->nosotros->id, 'environment' => Environment::Pruebas, 'ecf_type' => EcfType::CreditoFiscal,
        'range_from' => 1, 'range_to' => 10, 'next_number' => 1, 'is_active' => true, 'expires_at' => Carbon::create(2027, 12, 31),
    ]);
    $propio = app(ElectronicInvoiceService::class)->issue($this->nosotros, new EcfDocument(
        type: EcfType::CreditoFiscal, encf: 'E310000000000', issueDate: Carbon::create(2026, 10, 3),
        emitter: new EcfParty(taxId: '131000002', legalName: 'Nosotros SRL', address: 'Calle 1'),
        lines: [new EcfLine('Servicio', '1', '118.00', BillingIndicator::Itbis1)],
        buyer: new EcfParty(taxId: '101000007', legalName: 'Proveedor SRL'),
    ));
    app(CurrentCompany::class)->forget();

    $acecf = function (string $encf, string $estado = '1'): string {
        $d = new DOMDocument('1.0', 'utf-8');
        $det = $d->appendChild($d->createElement('ACECF'))->appendChild($d->createElement('DetalleAprobacionComercial'));
        foreach (['Version' => '1.0', 'RNCEmisor' => '131000002', 'eNCF' => $encf, 'FechaEmision' => '03-10-2026', 'MontoTotal' => '118.00',
            'RNCComprador' => '101000007', 'Estado' => $estado, 'FechaHoraAprobacionComercial' => '04-10-2026 10:00:00'] as $k => $v) {
            $det->appendChild($d->createElement($k, $v));
        }

        return app(XmlSigner::class)->sign($d, app(CertificateVault::class)->load($this->proveedor), now(), writeSignatureDate: false)->xml;
    };

    $token = ($this->token)();
    ($this->enviar)($acecf($propio->e_ncf), $token, '/fe/aprobacioncomercial/api/ecf')->assertOk();

    $propio = ElectronicInvoice::query()->withoutGlobalScopes()->find($propio->id);
    expect($propio->commercial_status)->toBe(1)
        ->and($propio->file('acecf'))->not->toBeNull();

    // Un e-NCF que no es nuestro: 400.
    ($this->enviar)($acecf('E310000000099'), $token, '/fe/aprobacioncomercial/api/ecf')->assertStatus(400);
});

it('la pantalla de recibidos enseña las direcciones a registrar y los e-CF con su acuse', function (): void {
    ($this->enviar)(($this->ecfDelProveedor)(), ($this->token)());
    $doc = ElectronicReceivedDocument::query()->withoutGlobalScopes()->sole();

    $duena = withRole(\App\Models\User::create([
        'company_id' => $this->nosotros->id, 'name' => 'Dueña', 'email' => 'duena@rx.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($duena)->get(route('panel.e-invoicing.received'))->assertOk()
        ->assertSee($this->ajustes->receiverUrls()['recepcion'])
        ->assertSee('E310000000007')->assertSee('Proveedor SRL')->assertSee('Recibido');

    $this->actingAs($duena)->get(route('panel.e-invoicing.received.file', [$doc->id, 'arecf']))
        ->assertOk()->assertSee('<ARECF>', false);
});

/*
 * Fases 7b y 7c: aprobación comercial que emitimos, y envío de nuestro e-CF aceptado al comprador.
 */

it('aceptamos o rechazamos un e-CF recibido: ACECF firmada, válida y enviada', function (): void {
    ($this->enviar)(($this->ecfDelProveedor)(), ($this->token)());
    ($this->enviar)(($this->ecfDelProveedor)('131000002', 'E310000000008'), ($this->token)());
    [$uno, $dos] = ElectronicReceivedDocument::query()->withoutGlobalScopes()->orderBy('id')->get()->all();

    $duena = withRole(\App\Models\User::create([
        'company_id' => $this->nosotros->id, 'name' => 'Dueña', 'email' => 'duena@aprob.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($duena)->post(route('panel.e-invoicing.received.approve', $uno->id), ['decision' => 'aceptar'])->assertSessionHas('panel_ok');
    $this->actingAs($duena)->post(route('panel.e-invoicing.received.approve', $dos->id), ['decision' => 'rechazar'])->assertSessionHasErrors('motivo');
    $this->actingAs($duena)->post(route('panel.e-invoicing.received.approve', $dos->id), ['decision' => 'rechazar', 'motivo' => 'Mercancía dañada'])->assertSessionHas('panel_ok');
    // Una sola aprobación por documento.
    $this->actingAs($duena)->post(route('panel.e-invoicing.received.approve', $uno->id), ['decision' => 'rechazar', 'motivo' => 'x'])->assertSessionHas('panel_error');

    $uno->refresh();
    $dos->refresh();
    $acecf = SafeXml::load(Storage::disk('local')->get($uno->acecf_path));

    expect($uno->approval_status)->toBe(1)
        ->and($uno->approval_dgii_result)->toBe('accepted')
        ->and($uno->approval_emitter_result)->toBe('no_aplica')
        ->and($dos->approval_status)->toBe(2)
        ->and($dos->approval_reason)->toBe('Mercancía dañada')
        ->and(app(XmlValidator::class)->validate($acecf, app(SchemaRegistry::class)->validationPath('acecf.xsd')))->toBe([])
        ->and(app(\App\Modules\ElectronicInvoicing\Signature\XmlSignatureVerifier::class)->verify($acecf)['valid'])->toBeTrue()
        ->and(Storage::disk('local')->get($dos->acecf_path))->toContain('<DetalleMotivoRechazo>Mercancía dañada</DetalleMotivoRechazo>');
});

it('con la DGII: la aprobación va a la DGII y al emisor; nuestro e-CF aceptado va al comprador electrónico', function (): void {
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $this->ajustes->forceFill(['provider' => 'dgii'])->save();

    // Un receptor ajeno (el proveedor) en otro host, con sus servicios estándar.
    $peer = 'https://receptor.proveedor.test/fe';
    Illuminate\Support\Facades\Http::fake([
        'ecf.dgii.gov.do/testecf/autenticacion/api/autenticacion/semilla' => Illuminate\Support\Facades\Http::response('<SemillaModel><valor>d</valor></SemillaModel>'),
        'ecf.dgii.gov.do/testecf/autenticacion/api/autenticacion/validarsemilla' => Illuminate\Support\Facades\Http::response(['token' => 'dgii', 'expira' => now()->addHour()->toIso8601String()]),
        'ecf.dgii.gov.do/testecf/aprobacioncomercial/api/aprobacioncomercial' => Illuminate\Support\Facades\Http::response(['mensaje' => ['Aprobación comercial aprobada'], 'estado' => 'Aprobada', 'codigo' => '1']),
        'ecf.dgii.gov.do/testecf/consultadirectorio/api/consultas/obtenerdirectorioporrnc*' => Illuminate\Support\Facades\Http::response([['nombre' => 'Proveedor SRL', 'rnc' => '101000007', 'urlRecepcion' => $peer, 'urlAceptacion' => $peer, 'urlOpcional' => $peer]]),
        'ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Illuminate\Support\Facades\Http::response(['trackId' => 't-1']),
        'ecf.dgii.gov.do/testecf/consultaresultado/api/consultas/estado*' => Illuminate\Support\Facades\Http::response(['codigo' => 1, 'estado' => 'Aceptado']),
        'receptor.proveedor.test/fe/fe/autenticacion/api/semilla' => Illuminate\Support\Facades\Http::response('<SemillaModel><valor>p</valor></SemillaModel>'),
        'receptor.proveedor.test/fe/fe/autenticacion/api/validacioncertificado' => Illuminate\Support\Facades\Http::response(['token' => 'peer', 'expira' => now()->addHour()->format('Y-m-d\TH:i:s\Z')]),
        'receptor.proveedor.test/fe/fe/aprobacioncomercial/api/ecf' => Illuminate\Support\Facades\Http::response('', 200),
        'receptor.proveedor.test/fe/fe/recepcion/api/ecf' => Illuminate\Support\Facades\Http::response('<ARECF><DetalleAcusedeRecibo><Version>1.0</Version><Estado>0</Estado></DetalleAcusedeRecibo></ARECF>'),
    ]);

    // 7b: aceptamos un e-CF recibido.
    ($this->enviar)(($this->ecfDelProveedor)(), ($this->token)());
    $recibido = ElectronicReceivedDocument::query()->withoutGlobalScopes()->sole();
    $recibido = app(\App\Modules\ElectronicInvoicing\Receiver\CommercialApprovalService::class)->emit($recibido, true, null, null);

    expect($recibido->approval_dgii_result)->toBe('accepted')
        ->and($recibido->approval_emitter_result)->toBe('enviado')
        ->and($recibido->approval_error)->toBeNull();
    Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_ends_with($r->url(), '/fe/aprobacioncomercial/api/ecf') && $r->hasHeader('Authorization', 'Bearer peer'));

    // 7c: emitimos un 31 al proveedor; aceptado por la DGII, va a su recepción.
    ElectronicNcfSequence::create([
        'company_id' => $this->nosotros->id, 'environment' => Environment::Pruebas, 'ecf_type' => EcfType::CreditoFiscal,
        'range_from' => 1, 'range_to' => 10, 'next_number' => 1, 'is_active' => true, 'expires_at' => Carbon::create(2027, 12, 31),
    ]);
    $propio = app(ElectronicInvoiceService::class)->issue($this->nosotros, new EcfDocument(
        type: EcfType::CreditoFiscal, encf: 'E310000000000', issueDate: Carbon::create(2026, 10, 3),
        emitter: new EcfParty(taxId: '131000002', legalName: 'Nosotros SRL', address: 'Calle 1'),
        lines: [new EcfLine('Servicio', '1', '118.00', BillingIndicator::Itbis1)],
        buyer: new EcfParty(taxId: '101000007', legalName: 'Proveedor SRL'),
    ));
    $propio = app(ElectronicInvoiceService::class)->query($propio);
    expect($propio->getAttributes()['buyer_delivery_status'])->toBe('pendiente');

    $r = app(ElectronicInvoiceService::class)->processPending();
    $propio->refresh();

    expect($r['entregados'])->toBe(1)
        ->and($propio->getAttributes()['buyer_delivery_status'])->toBe('enviado')
        ->and($propio->getAttributes()['buyer_receipt_status'])->toBe(0)
        ->and($propio->file('arecf_comprador'))->not->toBeNull();
    Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_ends_with($r->url(), '/fe/recepcion/api/ecf') && str_contains((string) $r->body(), 'E310000000001'));
});
