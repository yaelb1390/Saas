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

        /*
         * SIN ESPACIOS en la firma [DTEE «Firmado de XML»: «sin la preservación de los espacios,
         * preservewhitespace = false»]. La plantilla de xmlseclibs trae saltos de línea y sangría dentro
         * de <SignedInfo>; quien verifica cargando sin preservar espacios (la DGII, en .NET) los
         * descarta, el canónico de SignedInfo cambia y la firma deja de cuadrar. Se quitan ANTES de
         * firmar, para que lo firmado sea lo mismo que se verifica.
         */
        $this->sinEspacios($dsig->sigNode);

        $clave = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $clave->loadKey($certificate->privateKeyPem);

        $dsig->sign($clave);
        $dsig->add509Cert($certificate->certificatePem, true);
        $firma = $dsig->appendSignature($raiz);

        // Los que añaden SignatureValue y KeyInfo quedan fuera de lo firmado: quitarlos no altera la
        // firma y deja el documento sin espacios de principio a fin.
        $this->sinEspacios($firma);

        /*
         * La firma, recalculada YA DENTRO del documento.
         *
         * xmlseclibs firma <SignedInfo> suelto, antes de colocarlo. Con C14N inclusivo, quien verifica
         * lo canonicaliza en su sitio, heredando los espacios de nombres que declara la raíz
         * (xmlns:xsi, xmlns:xsd…) —lo hace xmlseclibs al verificar y lo hace .NET, que es lo que usa la
         * DGII—. Si la raíz los declara (la semilla de la DGII lo hace), lo firmado y lo verificado no
         * coinciden. Se firma el canónico del <SignedInfo> ya colocado; sin esas declaraciones, el
         * resultado es idéntico al de antes.
         */
        $this->firmarEnSitio($firma, $clave);

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

    private function firmarEnSitio(\DOMNode $firma, XMLSecurityKey $clave): void
    {
        $info = null;
        $valor = null;

        foreach ($firma->childNodes as $hijo) {
            if ($hijo instanceof DOMElement && $hijo->localName === 'SignedInfo') {
                $info = $hijo;
            } elseif ($hijo instanceof DOMElement && $hijo->localName === 'SignatureValue') {
                $valor = $hijo;
            }
        }

        if ($info === null || $valor === null) {
            throw new RuntimeException('La firma no tiene SignedInfo o SignatureValue.');
        }

        $canonico = $info->C14N(false, false);
        if ($canonico === false) {
            throw new RuntimeException('No se pudo canonicalizar SignedInfo.');
        }

        $valor->nodeValue = base64_encode((string) $clave->signData($canonico));
    }

    /** Quita los nodos de texto que solo tienen espacios (no el contenido de SignatureValue ni del certificado). */
    private function sinEspacios(?\DOMNode $nodo): void
    {
        if ($nodo === null) {
            return;
        }

        foreach (iterator_to_array($nodo->childNodes) as $hijo) {
            if ($hijo instanceof \DOMText && trim($hijo->nodeValue ?? '') === '') {
                $nodo->removeChild($hijo);
            } elseif ($hijo->hasChildNodes()) {
                $this->sinEspacios($hijo);
            }
        }
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
