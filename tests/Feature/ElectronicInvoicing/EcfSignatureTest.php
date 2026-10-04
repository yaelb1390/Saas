<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Application\EcfXmlGenerator;
use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfLine;
use App\Modules\ElectronicInvoicing\Domain\EcfParty;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Models\ElectronicCertificate;
use App\Modules\ElectronicInvoicing\Signature\CertificateException;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Signature\XmlSigner;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use App\Modules\ElectronicInvoicing\Xml\XmlValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/*
 * Fase 3: certificado y firma.
 *
 * El certificado es AUTOFIRMADO y se genera en cada prueba: nunca hay uno real en el repositorio.
 * La firma se verifica de forma independiente con xmlseclibs (otra ruta de código que la que firma),
 * y el XML firmado se valida contra el XSD OFICIAL sin marcador: es la prueba de que la firma ocupa
 * el hueco correcto y `FechaHoraFirma` tiene el formato exigido.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['filesystems.fiscal_documents' => 'local']);
    Storage::fake('local');

    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->p12 = function (string $clave = 'secreto-123', int $dias = 365): string {
        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'PRUEBA BMIA', 'serialNumber' => '00100000009', 'countryName' => 'DO'], $llave, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $llave, $dias, ['digest_alg' => 'sha256']);
        openssl_pkcs12_export($cert, $salida, $llave, $clave);

        return $salida;
    };

    $this->documento = new EcfDocument(
        type: EcfType::Consumo,
        encf: 'E320000000001',
        issueDate: Carbon::create(2026, 10, 3),
        emitter: new EcfParty(taxId: '131000002', legalName: 'Colmado SRL', address: 'Calle 1'),
        lines: [new EcfLine('Refresco', '2', '118.00', BillingIndicator::Itbis1)],
    );
});

it('guarda el certificado cifrado y su contraseña cifrada; nunca en claro', function (): void {
    $bytes = ($this->p12)();
    $cert = app(CertificateVault::class)->store($this->company, $bytes, 'secreto-123');

    $enDisco = Storage::disk('local')->get($cert->path);
    expect($enDisco)->not->toContain($bytes)
        ->and($enDisco)->not->toContain(base64_encode($bytes))
        ->and($enDisco)->not->toContain('BEGIN');

    $crudo = DB::table('electronic_certificates')->where('id', $cert->id)->value('password');
    expect($crudo)->not->toBe('secreto-123')->and($cert->fresh()->password)->toBe('secreto-123');

    $auditado = DB::table('audits')->where('auditable_type', ElectronicCertificate::class)->pluck('new_values')->implode(' ');
    expect($auditado)->not->toContain('secreto-123')->and($auditado)->not->toContain($cert->path);

    expect($cert->toArray())->not->toHaveKey('password')
        ->and($cert->subject)->toContain('PRUEBA BMIA')
        ->and($cert->status())->toBe('vigente')
        ->and(strlen($cert->fingerprint))->toBe(64);
});

it('rechaza una contraseña equivocada o un archivo que no es un certificado', function (): void {
    expect(fn () => app(CertificateVault::class)->store($this->company, ($this->p12)(), 'otra'))
        ->toThrow(CertificateException::class, 'contraseña no corresponde');
    expect(fn () => app(CertificateVault::class)->store($this->company, 'no soy un p12', 'x'))
        ->toThrow(CertificateException::class);

    expect(ElectronicCertificate::count())->toBe(0);
});

it('reemplazar el certificado desactiva el anterior sin borrarlo', function (): void {
    $vault = app(CertificateVault::class);
    $viejo = $vault->store($this->company, ($this->p12)(), 'secreto-123');
    $nuevo = $vault->store($this->company, ($this->p12)(), 'secreto-123');

    expect($viejo->fresh()->is_active)->toBeFalse()->and($viejo->fresh()->replaced_at)->not->toBeNull()
        ->and($nuevo->is_active)->toBeTrue()
        ->and($vault->active($this->company)->id)->toBe($nuevo->id);
});

it('se abre solo en memoria, y un volcado no enseña la clave privada', function (): void {
    app(CertificateVault::class)->store($this->company, ($this->p12)(), 'secreto-123');
    $abierto = app(CertificateVault::class)->load($this->company);

    expect($abierto->privateKeyPem)->toContain('PRIVATE KEY')
        ->and(print_r($abierto, true))->not->toContain('PRIVATE KEY');
    expect(fn () => serialize($abierto))->toThrow(LogicException::class);
});

it('una empresa no puede firmar con el certificado de otra', function (): void {
    app(CertificateVault::class)->store($this->company, ($this->p12)(), 'secreto-123');
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));

    expect(fn () => app(CertificateVault::class)->load($otra))->toThrow(CertificateException::class, 'no tiene un certificado');
});

it('el estado avisa cuando el certificado está por vencer o vencido', function (): void {
    $cert = app(CertificateVault::class)->store($this->company, ($this->p12)(), 'secreto-123');

    $cert->valid_to = now()->addDays(10);
    expect($cert->status())->toBe('por_vencer');

    $cert->valid_to = now()->subDay();
    expect($cert->status())->toBe('vencido');
});

it('firma con el perfil de la DGII y la firma se verifica de forma independiente', function (): void {
    app(CertificateVault::class)->store($this->company, ($this->p12)(), 'secreto-123');
    $xml = app(EcfXmlGenerator::class)->generate($this->documento)->xml;

    $firmado = app(XmlSigner::class)->sign($xml, app(CertificateVault::class)->load($this->company), Carbon::parse('2026-10-03 16:05:09', 'UTC'));

    // Perfil exigido [FIR]: enveloped, C14N inclusivo, RSA-SHA256, SHA-256, solo X509Certificate.
    expect($firmado->xml)
        ->toContain('<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">')
        ->toContain('<Reference URI="">')
        ->toContain('Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"')
        ->toContain('Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"')
        ->toContain('Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"')
        ->toContain('Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"')
        ->toContain('<X509Certificate>')
        ->not->toContain('KeyValue')
        // GMT-4: 16:05:09 UTC → 12:05:09.
        ->toContain('<FechaHoraFirma>03-10-2026 12:05:09</FechaHoraFirma>');

    // Verificación independiente.
    $doc = new DOMDocument;
    $doc->loadXML($firmado->xml);
    $dsig = new XMLSecurityDSig;
    $nodo = $dsig->locateSignature($doc);
    $dsig->canonicalizeSignedInfo();
    expect($dsig->validateReference())->toBeTrue();

    $clave = $dsig->locateKey();
    XMLSecEnc_cargarCertificado($clave, $nodo);
    expect($dsig->verify($clave))->toBe(1);

    expect($firmado->securityCode)->toBe(substr($firmado->signatureValue, 0, 6))
        ->and(strlen($firmado->securityCode))->toBe(6);
});

it('el XML firmado es válido contra el XSD oficial, ya sin marcador', function (): void {
    app(CertificateVault::class)->store($this->company, ($this->p12)(), 'secreto-123');
    $xml = app(EcfXmlGenerator::class)->generate($this->documento)->xml;
    $firmado = app(XmlSigner::class)->sign($xml, app(CertificateVault::class)->load($this->company), now());

    $doc = new DOMDocument;
    $doc->loadXML($firmado->xml);

    expect(app(XmlValidator::class)->validate($doc, app(SchemaRegistry::class)->validationPathForType(EcfType::Consumo)))->toBe([]);
});

it('cambiar un solo dato después de firmar invalida la firma', function (): void {
    app(CertificateVault::class)->store($this->company, ($this->p12)(), 'secreto-123');
    $xml = app(EcfXmlGenerator::class)->generate($this->documento)->xml;
    $firmado = app(XmlSigner::class)->sign($xml, app(CertificateVault::class)->load($this->company), now());

    $alterado = str_replace('<MontoTotal>236.00</MontoTotal>', '<MontoTotal>23.60</MontoTotal>', $firmado->xml);
    expect($alterado)->not->toBe($firmado->xml);

    $doc = new DOMDocument;
    $doc->loadXML($alterado);
    $dsig = new XMLSecurityDSig;
    $dsig->locateSignature($doc);
    $dsig->canonicalizeSignedInfo();

    expect(fn () => $dsig->validateReference())->toThrow(Exception::class, 'Reference validation failed');
});

it('factura de consumo bajo el umbral: resumen (RFCE) firmado y válido contra su XSD', function (): void {
    app(CertificateVault::class)->store($this->company, ($this->p12)(), 'secreto-123');
    $cert = app(CertificateVault::class)->load($this->company);
    $generador = app(EcfXmlGenerator::class);

    $e32 = app(XmlSigner::class)->sign($generador->generate($this->documento)->xml, $cert, now());
    $rfce = $generador->generateRfce($this->documento, $e32->securityCode);

    expect($rfce->errors)->toBe([]);
    $xml = $rfce->xml->saveXML();
    expect($xml)->toContain('<RFCE>')
        ->toContain("<CodigoSeguridadeCF>{$e32->securityCode}</CodigoSeguridadeCF>")
        ->toContain('<MontoTotal>236.00</MontoTotal>')
        // El resumen no lleva detalle de ítems ni fecha de firma.
        ->not->toContain('DetallesItems')->not->toContain('FechaHoraFirma');

    $firmado = app(XmlSigner::class)->sign($rfce->xml, $cert, now(), writeSignatureDate: false);
    $doc = new DOMDocument;
    $doc->loadXML($firmado->xml);

    expect(app(XmlValidator::class)->validate($doc, app(SchemaRegistry::class)->validationPath('rfce-32.xsd')))->toBe([]);
});

it('el umbral del resumen: bajo RD$250.000 resumen; desde 250.000 e-CF completo; otros tipos nunca', function (): void {
    $g = app(EcfXmlGenerator::class);

    expect($g->sendsSummary($this->documento, '249999.99'))->toBeTrue()
        ->and($g->sendsSummary($this->documento, '250000.00'))->toBeFalse()
        ->and($g->sendsSummary(new EcfDocument(
            type: EcfType::CreditoFiscal, encf: 'E310000000001', issueDate: now(), emitter: $this->documento->emitter, lines: [],
        ), '100.00'))->toBeFalse();
});

it('el dueño sube el certificado desde la pantalla; la contraseña nunca vuelve a la página', function (): void {
    $this->company->forceFill(['modules' => null])->save();
    $duena = withRole(\App\Models\User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@cert.test', 'password' => 'secret-password',
    ]), 'owner');

    $archivo = \Illuminate\Http\UploadedFile::fake()->createWithContent('firma.p12', ($this->p12)('clave-del-cert'));

    $this->actingAs($duena)->post(route('panel.e-invoicing.certificate.store'), [
        'certificate' => $archivo, 'password' => 'clave-del-cert',
    ])->assertSessionHas('panel_ok');

    $this->actingAs($duena)->get(route('panel.e-invoicing'))
        ->assertOk()->assertSee('PRUEBA BMIA')->assertSee('Vigente hasta')
        ->assertDontSee('clave-del-cert');

    // Contraseña equivocada: no se guarda nada nuevo y se explica.
    $otro = \Illuminate\Http\UploadedFile::fake()->createWithContent('otra.p12', ($this->p12)('x'));
    $this->actingAs($duena)->post(route('panel.e-invoicing.certificate.store'), [
        'certificate' => $otro, 'password' => 'equivocada',
    ])->assertSessionHasErrors('certificate');

    expect(ElectronicCertificate::count())->toBe(1);
});

it('distingue el certificado que no se eligió del que llegó a medias, y deja constancia', function (): void {
    $this->company->forceFill(['modules' => null])->save();
    $duena = withRole(\App\Models\User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@subida.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($duena)->post(route('panel.e-invoicing.certificate.store'), ['password' => 'clave'])
        ->assertSessionHasErrors(['certificate' => 'No llegó ningún archivo. Elige el .p12 o .pfx con «Seleccionar archivo» y vuelve a guardar.']);

    // Un archivo que PHP recibió a medias (UPLOAD_ERR_PARTIAL) Laravel lo trata como ausente.
    $temporal = tempnam(sys_get_temp_dir(), 'p12');
    file_put_contents($temporal, 'a medias');
    $roto = new \Illuminate\Http\UploadedFile($temporal, 'firma.p12', 'application/x-pkcs12', UPLOAD_ERR_PARTIAL, true);

    $this->actingAs($duena)->post(route('panel.e-invoicing.certificate.store'), ['certificate' => $roto, 'password' => 'clave'])
        ->assertSessionHasErrors(['certificate' => 'El archivo se envió pero no llegó completo al servidor (código 3). Vuelve a intentarlo; si se repite, avisa a soporte.']);

    expect(\App\Modules\Core\Models\SystemEvent::query()->where('type', 'ecf.certificate_upload_failed')->count())->toBe(1)
        ->and(ElectronicCertificate::count())->toBe(0);

    @unlink($temporal);
});

it('el cajero no sube certificados', function (): void {
    $cajero = withRole(\App\Models\User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero', 'email' => 'cajero@cert.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->post(route('panel.e-invoicing.certificate.store'), [
        'certificate' => \Illuminate\Http\UploadedFile::fake()->createWithContent('f.p12', ($this->p12)()), 'password' => 'secreto-123',
    ])->assertForbidden();
});

/** Carga en la clave la clave pública del X509Certificate del propio documento. */
function XMLSecEnc_cargarCertificado(XMLSecurityKey $clave, DOMElement $firma): void
{
    $x509 = $firma->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'X509Certificate')->item(0)->textContent;
    $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(preg_replace('/\s+/', '', $x509), 64, "\n")."-----END CERTIFICATE-----\n";
    $clave->loadKey($pem, false, true);
}

it('la firma no lleva espacios y se verifica también cargando sin preservar espacios (como la DGII)', function (): void {
    // [DTEE «Firmado de XML»] firmar sin preservar espacios. La plantilla de xmlseclibs metía saltos de
    // línea en <SignedInfo>: cargando con preserveWhiteSpace = false la firma dejaba de cuadrar.
    app(CertificateVault::class)->store($this->company, ($this->p12)(), 'secreto-123');
    $firmado = app(XmlSigner::class)->sign(app(EcfXmlGenerator::class)->generate($this->documento)->xml, app(CertificateVault::class)->load($this->company), now());

    // Dentro del documento (el salto tras la declaración XML del principio queda fuera y no se firma).
    expect(substr($firmado->xml, (int) strpos($firmado->xml, '<ECF')))->not->toMatch('/>\s+</');

    $doc = new DOMDocument;
    $doc->preserveWhiteSpace = false;
    $doc->loadXML($firmado->xml);

    expect(app(\App\Modules\ElectronicInvoicing\Signature\XmlSignatureVerifier::class)->verify($doc)['valid'])->toBeTrue();
});

it('firma bien un documento cuya raíz declara espacios de nombres (como la semilla de la DGII)', function (): void {
    // Con C14N inclusivo, <SignedInfo> se verifica heredando xmlns:xsi/xmlns:xsd de la raíz (xmlseclibs y
    // .NET). Firmarlo suelto, como hace xmlseclibs, no los incluía y la firma no cuadraba.
    app(CertificateVault::class)->store($this->company, ($this->p12)(), 'secreto-123');
    $doc = new DOMDocument;
    $doc->loadXML('<SemillaModel xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema"><valor>abc</valor><fecha>2026-10-04T10:00:00-04:00</fecha></SemillaModel>');

    $firmado = app(XmlSigner::class)->sign($doc, app(CertificateVault::class)->load($this->company), now(), writeSignatureDate: false);

    $verifica = new DOMDocument;
    $verifica->loadXML($firmado->xml);
    expect(app(\App\Modules\ElectronicInvoicing\Signature\XmlSignatureVerifier::class)->verify($verifica)['valid'])->toBeTrue();
});
