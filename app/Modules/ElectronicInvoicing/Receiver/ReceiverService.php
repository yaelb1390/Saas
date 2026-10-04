<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Receiver;

use App\Modules\Billing\Support\TaxId;
use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoiceAuditLog;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicReceivedDocument;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Signature\XmlSignatureVerifier;
use App\Modules\ElectronicInvoicing\Signature\XmlSigner;
use App\Modules\ElectronicInvoicing\Storage\FiscalDocumentStore;
use App\Modules\ElectronicInvoicing\Xml\SafeXml;
use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use App\Modules\ElectronicInvoicing\Xml\XmlValidator;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * La empresa como RECEPTORA de e-CF de otros contribuyentes [Descripción Técnica Servicios Emisores
 * Electrónicos (DTEE) 29/05/2026, «Estándar como Receptor Electrónico»]:
 *
 *   · autenticación (opcional en el estándar): semilla → semilla firmada → token Bearer;
 *   · recepción (obligatoria): recibe el XML del e-CF y devuelve un ACUSE DE RECIBO (ARECF) firmado,
 *     con Estado 0 recibido / 1 no recibido y, si no, su motivo [arecf.xsd]:
 *       1 error de especificación · 2 error de firma digital · 3 envío duplicado · 4 RNC comprador
 *       no corresponde;
 *   · aprobación comercial (obligatoria): recibe la ACECF que el comprador emite sobre un e-CF de la
 *     empresa; HTTP 200 si se procesa, 400 si no.
 *
 * Todo lo recibido se guarda tal cual (bytes y sha256) en el disco privado y se conserva 10 años.
 */
final class ReceiverService
{
    /** Vida de una semilla sin usar y de un token emitido. */
    private const SEMILLA_SEGUNDOS = 300;

    private const TOKEN_SEGUNDOS = 3600;

    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly XmlValidator $validator,
        private readonly XmlSignatureVerifier $verifier,
        private readonly XmlSigner $signer,
        private readonly CertificateVault $certificates,
        private readonly FiscalDocumentStore $files,
    ) {}

    /** [DTEE /fe/autenticacion/api/semilla] SemillaModel con un valor único. */
    public function seed(Company $company): string
    {
        $valor = base64_encode(random_bytes(48));
        Cache::put($this->claveSemilla((int) $company->id, $valor), true, self::SEMILLA_SEGUNDOS);

        $doc = new DOMDocument('1.0', 'utf-8');
        $raiz = $doc->appendChild($doc->createElement('SemillaModel'));
        $raiz->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $raiz->setAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $raiz->appendChild($doc->createElement('valor', $valor));
        $raiz->appendChild($doc->createElement('fecha', CarbonImmutable::now((string) config('ecf.formats.signature_utc_offset', '-04:00'))->format('Y-m-d\TH:i:s.uP')));

        return (string) $doc->saveXML();
    }

    /**
     * [DTEE /fe/autenticacion/api/validacioncertificado] La semilla firmada → token. La semilla tiene
     * que ser una emitida aquí, sin usar y vigente, y la firma válida.
     *
     * @return array{token: string, expira: string, expedido: string}
     */
    public function validateSeed(Company $company, string $xml): array
    {
        $doc = SafeXml::load($xml);
        $valor = trim((string) (new DOMXPath($doc))->evaluate("string(//*[local-name()='valor'][1])"));

        if ($valor === '' || ! Cache::pull($this->claveSemilla((int) $company->id, $valor))) {
            throw new RuntimeException('La semilla no es válida o ya venció.');
        }

        $firma = $this->verifier->verify($doc);
        if (! $firma['valid']) {
            throw new RuntimeException((string) $firma['error']);
        }

        $token = Str::random(64);
        $ahora = CarbonImmutable::now('UTC');
        $expira = $ahora->addSeconds(self::TOKEN_SEGUNDOS);
        Cache::put($this->claveToken((int) $company->id, $token), $expira->toIso8601String(), $expira);

        // Formato universal yyyy-MM-ddTHH:mm:ssZ [DTEE validacioncertificado].
        return ['token' => $token, 'expira' => $expira->format('Y-m-d\TH:i:s\Z'), 'expedido' => $ahora->format('Y-m-d\TH:i:s\Z')];
    }

    public function tokenIsValid(Company $company, ?string $token): bool
    {
        return $token !== null && $token !== '' && Cache::has($this->claveToken((int) $company->id, $token));
    }

    /**
     * [DTEE /fe/recepcion/api/ecf] Recibe un e-CF y devuelve el ARECF firmado (siempre: también cuando
     * no se recibe, con su motivo).
     */
    public function receiveEcf(Company $company, string $xml, ?string $ip = null): string
    {
        $settings = ElectronicInvoicingSettings::paraEmpresa($company);
        $nuestroRnc = (string) $settings->tax_id;
        $datos = ['emisor' => null, 'comprador' => null, 'encf' => null, 'tipo' => null, 'fecha' => null, 'total' => null, 'itbis' => null, 'nombre' => null];
        [$estado, $motivo, $detalle] = [ElectronicReceivedDocument::RECIBIDO, null, null];

        try {
            $doc = SafeXml::load($xml);
            $x = new DOMXPath($doc);
            $v = fn (string $tag): ?string => ($s = trim((string) $x->evaluate("string(//*[local-name()='{$tag}'][1])"))) === '' ? null : $s;

            $datos = [
                'emisor' => $v('RNCEmisor'), 'comprador' => $v('RNCComprador'), 'encf' => $v('eNCF'),
                'tipo' => $v('TipoeCF'), 'fecha' => $v('FechaEmision'), 'total' => $v('MontoTotal'),
                'itbis' => $v('TotalITBIS'), 'nombre' => $v('RazonSocialEmisor'),
            ];

            $tipo = EcfType::tryFrom((int) $datos['tipo']);
            $errores = $doc->documentElement?->localName === 'ECF' && $tipo !== null
                ? $this->validator->validate($doc, $this->schemas->validationPathForType($tipo))
                : null;

            if ($errores === null || $errores !== []) {
                [$estado, $motivo, $detalle] = [ElectronicReceivedDocument::NO_RECIBIDO, 1, $errores === null ? 'No es un e-CF.' : $errores[0]->message];
            } elseif (! ($firma = $this->verifier->verify($doc))['valid']) {
                [$estado, $motivo, $detalle] = [ElectronicReceivedDocument::NO_RECIBIDO, 2, $firma['error']];
            } elseif (preg_replace('/\D/', '', (string) $datos['comprador']) !== $nuestroRnc) {
                [$estado, $motivo, $detalle] = [ElectronicReceivedDocument::NO_RECIBIDO, 4, 'El comprador no es esta empresa.'];
            } elseif (ElectronicReceivedDocument::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)->where('environment', $settings->environment->value)
                ->where('emitter_tax_id', $datos['emisor'])->where('e_ncf', $datos['encf'])
                ->where('receipt_status', ElectronicReceivedDocument::RECIBIDO)->exists()) {
                [$estado, $motivo, $detalle] = [ElectronicReceivedDocument::NO_RECIBIDO, 3, 'Ya se había recibido este e-CF.'];
            }
        } catch (Throwable $e) {
            [$estado, $motivo, $detalle] = [ElectronicReceivedDocument::NO_RECIBIDO, 1, $e->getMessage()];
        }

        $arecf = $this->acuse($company, $datos, $nuestroRnc, $estado, $motivo);
        $carpeta = sprintf('ecf/%d/%s/recibidos/%s/%s%s', $company->id, $settings->environment->value, now()->format('Y/m'), $this->rnc($datos['emisor']) ?? 'desconocido', $this->limpio($datos['encf']) ?? Str::ulid());
        $disco = CertificateVault::disk();
        $disco->put($carpeta.'/ecf.xml', $xml, ['throw' => true]);
        $disco->put($carpeta.'/arecf.xml', $arecf, ['throw' => true]);

        ElectronicReceivedDocument::query()->create([
            'company_id' => $company->id,
            'environment' => $settings->environment,
            'emitter_tax_id' => $this->rnc($datos['emisor']),
            'emitter_name' => $datos['nombre'] !== null ? mb_substr($datos['nombre'], 0, 255) : null,
            'buyer_tax_id' => $this->rnc($datos['comprador']),
            'e_ncf' => $this->limpio($datos['encf']),
            'ecf_type' => EcfType::tryFrom((int) $datos['tipo'])?->value,
            'issue_date' => $this->fecha($datos['fecha']),
            'total' => is_numeric($datos['total']) ? $datos['total'] : null,
            'itbis_total' => is_numeric($datos['itbis']) ? $datos['itbis'] : null,
            'receipt_status' => $estado,
            'receipt_reason' => $motivo,
            'receipt_detail' => $detalle !== null ? mb_substr($detalle, 0, 500) : null,
            'xml_path' => $carpeta.'/ecf.xml',
            'xml_sha256' => hash('sha256', $xml),
            'arecf_path' => $carpeta.'/arecf.xml',
            'arecf_sha256' => hash('sha256', $arecf),
            'sender_ip' => $ip,
        ]);

        return $arecf;
    }

    /**
     * [DTEE /fe/aprobacioncomercial/api/ecf] La aprobación comercial (ACECF) que el comprador emite
     * sobre un e-CF de la empresa. Devuelve null si se procesó (HTTP 200) o el motivo (HTTP 400).
     */
    public function receiveApproval(Company $company, string $xml): ?string
    {
        try {
            $doc = SafeXml::load($xml);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        if ($doc->documentElement?->localName !== 'ACECF') {
            return 'No es una aprobación comercial (ACECF).';
        }

        $errores = $this->validator->validate($doc, $this->schemas->validationPath('acecf.xsd'));
        if ($errores !== []) {
            return $errores[0]->message;
        }

        $firma = $this->verifier->verify($doc);
        if (! $firma['valid']) {
            return (string) $firma['error'];
        }

        $x = new DOMXPath($doc);
        $v = fn (string $tag): string => trim((string) $x->evaluate("string(//*[local-name()='{$tag}'][1])"));
        $settings = ElectronicInvoicingSettings::paraEmpresa($company);

        if ($v('RNCEmisor') !== (string) $settings->tax_id) {
            return 'El emisor de ese e-CF no es esta empresa.';
        }

        $ecf = ElectronicInvoice::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('environment', $settings->environment->value)
            ->where('e_ncf', $v('eNCF'))
            ->latest('id')
            ->first();

        if ($ecf === null) {
            return 'Factura no encontrada para esta aprobación comercial.';
        }

        $this->files->put($ecf, 'acecf', $xml);
        $ecf->forceFill([
            'commercial_status' => (int) $v('Estado'),
            'commercial_reason' => mb_substr($v('DetalleMotivoRechazo'), 0, 250) ?: null,
            'commercial_at' => now(),
        ])->save();

        ElectronicInvoiceAuditLog::create([
            'company_id' => $company->id,
            'electronic_invoice_id' => $ecf->id,
            'e_ncf' => $ecf->e_ncf,
            'action' => (int) $v('Estado') === 1 ? 'Aprobación comercial recibida: aceptado' : 'Aprobación comercial recibida: rechazado',
            'details' => ['comprador' => $v('RNCComprador'), 'motivo' => $v('DetalleMotivoRechazo') ?: null],
        ]);

        return null;
    }

    /**
     * ARECF firmado con el certificado de la empresa y validado contra arecf.xsd.
     *
     * @param  array<string, ?string>  $d
     */
    private function acuse(Company $company, array $d, string $nuestroRnc, int $estado, ?int $motivo): string
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $raiz = $doc->appendChild($doc->createElement('ARECF'));
        $det = $raiz->appendChild($doc->createElement('DetalleAcusedeRecibo'));

        $campos = [
            'Version' => '1.0',
            // Si lo recibido es ilegible, el XSD exige un RNC con forma: se pone el propio.
            'RNCEmisor' => $this->rnc($d['emisor']) ?? $nuestroRnc,
            'RNCComprador' => $this->rnc($d['comprador']) ?? $nuestroRnc,
            'eNCF' => $this->limpio($d['encf']) ?? 'E000000000000',
            'Estado' => (string) $estado,
            'CodigoMotivoNoRecibido' => $motivo !== null ? (string) $motivo : null,
            'FechaHoraAcuseRecibo' => CarbonImmutable::now((string) config('ecf.formats.signature_utc_offset', '-04:00'))->format((string) config('ecf.formats.datetime', 'd-m-Y H:i:s')),
        ];

        foreach ($campos as $nombre => $valor) {
            if ($valor !== null) {
                $det->appendChild($doc->createElement($nombre))->appendChild($doc->createTextNode($valor));
            }
        }

        $firmado = $this->signer->sign($doc, $this->certificates->load($company), now(), writeSignatureDate: false);

        $verif = SafeXml::load($firmado->xml);
        $errores = $this->validator->validate($verif, $this->schemas->validationPath('arecf.xsd'));
        if ($errores !== []) {
            throw new RuntimeException('El acuse de recibo no cumple su esquema: '.$errores[0]->message);
        }

        return $firmado->xml;
    }

    private function rnc(?string $valor): ?string
    {
        $d = preg_replace('/\D/', '', (string) $valor);

        return in_array(strlen((string) $d), [9, 11], true) ? $d : null;
    }

    private function limpio(?string $encf): ?string
    {
        return $encf !== null && preg_match('/^[A-Za-z0-9]{11,19}$/', $encf) === 1 ? strtoupper($encf) : null;
    }

    private function fecha(?string $dmy): ?string
    {
        try {
            return $dmy !== null ? CarbonImmutable::createFromFormat('d-m-Y', $dmy)->toDateString() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function claveSemilla(int $companyId, string $valor): string
    {
        return "ecf:rx-seed:{$companyId}:".hash('sha256', $valor);
    }

    private function claveToken(int $companyId, string $token): string
    {
        return "ecf:rx-token:{$companyId}:".hash('sha256', $token);
    }
}
