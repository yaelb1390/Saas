<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Signature;

use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

/**
 * Firma un e-CF con el perfil que exige la DGII [FIR pp.2–3; FMT §G–H]:
 *
 *   · XMLDSig envuelta (enveloped) sobre el documento completo (`Reference URI=""`);
 *   · canonicalización C14N inclusiva 20010315, sin comentarios;
 *   · firma RSA-SHA256 y resumen SHA-256;
 *   · `KeyInfo` con `X509Data/X509Certificate` (sin `KeyValue`, igual que el ejemplo oficial);
 *   · `<FechaHoraFirma>` en GMT-4, `dd-MM-yyyy HH:mm:ss`, escrita ANTES de firmar (queda firmada).
 *
 * Usa robrichards/xmlseclibs. El ejemplo oficial de la DGII usa selective/xmldsig, pero ese paquete
 * está abandonado y su autor remite a xmlseclibs; el resultado es el mismo perfil de firma.
 *
 * La firma va como último hijo de la raíz: es el `xs:any` obligatorio con que termina el XSD.
 */
final class XmlSigner
{
    /**
     * @param  bool  $writeSignatureDate  false para documentos cuyo esquema no tiene FechaHoraFirma (RFCE).
     */
    public function sign(DOMDocument $unsigned, LoadedCertificate $certificate, CarbonInterface $at, bool $writeSignatureDate = true): SignedXml
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        $doc->loadXML((string) $unsigned->saveXML(), LIBXML_NONET);

        $raiz = $doc->documentElement ?? throw new RuntimeException('El XML no tiene elemento raíz.');
        $firmadoEn = $at->copy()->setTimezone((string) config('ecf.formats.signature_utc_offset', '-04:00'));

        if ($writeSignatureDate) {
            $this->fechaHoraFirma($doc, $raiz, $firmadoEn->format((string) config('ecf.formats.datetime', 'd-m-Y H:i:s')));
        }

        // Prefijo vacío: <Signature xmlns="http://www.w3.org/2000/09/xmldsig#">, como el ejemplo oficial.
        $dsig = new XMLSecurityDSig('');
        $dsig->setCanonicalMethod(XMLSecurityDSig::C14N);
        $dsig->addReference(
            $doc,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
            ['force_uri' => true],
        );

        $clave = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $clave->loadKey($certificate->privateKeyPem);

        $dsig->sign($clave);
        $dsig->add509Cert($certificate->certificatePem, true);
        $dsig->appendSignature($raiz);

        $xml = (string) $doc->saveXML();
        $valorFirma = $this->signatureValue($doc);

        return new SignedXml(
            xml: $xml,
            signatureValue: $valorFirma,
            securityCode: SecurityCode::fromSignatureValue($valorFirma),
            signedAt: $firmadoEn,
            certificateFingerprint: $certificate->record->fingerprint,
        );
    }

    /** Escribe o reemplaza <FechaHoraFirma> como último hijo antes de la firma. */
    private function fechaHoraFirma(DOMDocument $doc, DOMElement $raiz, string $valor): void
    {
        foreach ($raiz->childNodes as $hijo) {
            if ($hijo instanceof DOMElement && $hijo->localName === 'FechaHoraFirma') {
                $raiz->removeChild($hijo);
                break;
            }
        }

        $elemento = $doc->createElement('FechaHoraFirma');
        $elemento->appendChild($doc->createTextNode($valor));
        $raiz->appendChild($elemento);
    }

    private function signatureValue(DOMDocument $doc): string
    {
        $nodo = $doc->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'SignatureValue')->item(0);

        if ($nodo === null) {
            throw new RuntimeException('La firma no generó SignatureValue.');
        }

        return preg_replace('/\s+/', '', $nodo->textContent) ?? '';
    }
}
