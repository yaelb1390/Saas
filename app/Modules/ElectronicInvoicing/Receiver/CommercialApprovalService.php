<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Receiver;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicReceivedDocument;
use App\Modules\ElectronicInvoicing\Providers\ProviderResolver;
use App\Modules\ElectronicInvoicing\Providers\ReceiverLookup;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Signature\XmlSigner;
use App\Modules\ElectronicInvoicing\Xml\SafeXml;
use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use App\Modules\ElectronicInvoicing\Xml\XmlValidator;
use Carbon\CarbonImmutable;
use DOMDocument;
use DomainException;
use RuntimeException;
use Throwable;

/**
 * La aprobación comercial (ACECF) que la empresa, como COMPRADORA, emite sobre un e-CF que recibió:
 * acepta (1) o rechaza (2) la transacción [acecf.xsd; DT pp.31–33].
 *
 *   1. ACECF firmada con el certificado de la empresa y validada contra acecf.xsd;
 *   2. a la DGII (servicio aprobacioncomercial): 1 aprobada · 2 rechazada;
 *   3. al emisor, a su `urlAceptacion` del directorio (autenticándose en su `urlOpcional` si la tiene).
 *
 * Nombre del archivo: RNCComprador + e-NCF [DTEE «Nombre de los Archivos XML»]. Se emite UNA vez por
 * documento; lo que falle al enviar queda anotado para revisarlo.
 */
final class CommercialApprovalService
{
    public function __construct(
        private readonly XmlSigner $signer,
        private readonly CertificateVault $certificates,
        private readonly SchemaRegistry $schemas,
        private readonly XmlValidator $validator,
        private readonly ProviderResolver $providers,
        private readonly PeerClient $peers,
    ) {}

    public function emit(ElectronicReceivedDocument $doc, bool $accept, ?string $reason, ?int $userId): ElectronicReceivedDocument
    {
        if ($doc->receipt_status !== ElectronicReceivedDocument::RECIBIDO) {
            throw new DomainException('Solo se aprueba o rechaza un comprobante que se recibió.');
        }

        if ($doc->approval_status !== null) {
            throw new DomainException('Este comprobante ya tiene su aprobación comercial.');
        }

        if (! $accept && blank($reason)) {
            throw new DomainException('Para rechazar hay que indicar el motivo.');
        }

        $company = Company::query()->findOrFail($doc->company_id);
        $settings = ElectronicInvoicingSettings::paraEmpresa($company);
        $xml = $this->acecf($company, $doc, (string) $settings->tax_id, $accept, $reason);

        $ruta = dirname((string) $doc->xml_path).'/acecf.xml';
        CertificateVault::disk()->put($ruta, $xml, ['throw' => true]);

        $doc->forceFill([
            'approval_status' => $accept ? 1 : 2,
            'approval_reason' => $accept ? null : mb_substr((string) $reason, 0, 250),
            'acecf_path' => $ruta,
            'acecf_sha256' => hash('sha256', $xml),
            'approved_by' => $userId,
        ])->save();

        $proveedor = $this->providers->for($settings);
        $nombre = $settings->tax_id.$doc->e_ncf.'.xml';
        $errores = [];

        // 2. A la DGII.
        $dgii = $proveedor->sendCommercialApproval($company, $settings->environment, $xml, $nombre);
        if (! in_array($dgii->outcome->value, ['accepted', 'accepted_conditional'], true)) {
            $errores[] = 'DGII: '.$dgii->summary();
        }

        // 3. Al emisor, si es receptor electrónico y el proveedor sabe buscarlo.
        $emisor = 'no_aplica';
        $directorio = $proveedor->findReceiver($company, $settings->environment, (string) $doc->emitter_tax_id);

        if ($directorio->status === ReceiverLookup::FOUND && $directorio->approvalUrl !== null) {
            try {
                $r = $this->peers->post(PeerClient::endpoint($directorio->approvalUrl, PeerClient::APROBACION), $xml, $nombre, $this->peers->token($company, $directorio->authUrl));
                $emisor = $r->successful() ? 'enviado' : 'error';
                if (! $r->successful()) {
                    $errores[] = "Emisor: HTTP {$r->status()} ".mb_substr($r->body(), 0, 200);
                }
            } catch (Throwable $e) {
                $emisor = 'error';
                $errores[] = 'Emisor: '.$e->getMessage();
            }
        } elseif ($directorio->status === ReceiverLookup::NOT_ELECTRONIC) {
            $emisor = 'no_electronico';
        } elseif ($directorio->status === ReceiverLookup::ERROR) {
            $emisor = 'error';
            $errores[] = 'Directorio: '.$directorio->error;
        }

        $doc->forceFill([
            'approval_sent_at' => now(),
            'approval_dgii_result' => $dgii->outcome->value,
            'approval_emitter_result' => $emisor,
            'approval_error' => $errores === [] ? null : mb_substr(implode(' · ', $errores), 0, 500),
        ])->save();

        return $doc->refresh();
    }

    private function acecf(Company $company, ElectronicReceivedDocument $doc, string $nuestroRnc, bool $accept, ?string $reason): string
    {
        $d = new DOMDocument('1.0', 'utf-8');
        $det = $d->appendChild($d->createElement('ACECF'))->appendChild($d->createElement('DetalleAprobacionComercial'));
        $zona = (string) config('ecf.formats.signature_utc_offset', '-04:00');

        $campos = [
            'Version' => '1.0',
            'RNCEmisor' => (string) $doc->emitter_tax_id,
            'eNCF' => (string) $doc->e_ncf,
            'FechaEmision' => $doc->issue_date?->format((string) config('ecf.formats.date', 'd-m-Y')) ?? '',
            'MontoTotal' => number_format((float) $doc->total, 2, '.', ''),
            'RNCComprador' => $nuestroRnc,
            'Estado' => $accept ? '1' : '2',
            // Sin etiquetas vacías [DTEE «Restricciones de contenido»]: el motivo solo si se rechaza.
            'DetalleMotivoRechazo' => $accept ? null : mb_substr((string) $reason, 0, 250),
            'FechaHoraAprobacionComercial' => CarbonImmutable::now($zona)->format((string) config('ecf.formats.datetime', 'd-m-Y H:i:s')),
        ];

        foreach ($campos as $nombre => $valor) {
            if ($valor !== null) {
                $det->appendChild($d->createElement($nombre))->appendChild($d->createTextNode($valor));
            }
        }

        $firmado = $this->signer->sign($d, $this->certificates->load($company), now(), writeSignatureDate: false)->xml;
        $errores = $this->validator->validate(SafeXml::load($firmado), $this->schemas->validationPath('acecf.xsd'));

        if ($errores !== []) {
            throw new RuntimeException('La aprobación comercial no cumple su esquema: '.$errores[0]->message);
        }

        return $firmado;
    }
}
