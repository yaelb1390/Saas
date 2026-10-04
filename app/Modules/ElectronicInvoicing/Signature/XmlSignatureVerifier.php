<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Signature;

use DOMDocument;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use Throwable;

/**
 * Verifica la firma XMLDSig de un documento que llega de FUERA (e-CF recibido, aprobación comercial,
 * semilla firmada) con el certificado X509 que trae el propio documento [FIR].
 *
 * Comprueba que el contenido no cambió (referencia) y que la firma corresponde a la clave del
 * certificado. No decide si ese certificado es de quien dice ser: eso lo garantiza la DGII al
 * aceptar el e-CF; aquí solo se rechaza lo alterado o mal firmado (acuse «Error de firma digital»).
 */
final class XmlSignatureVerifier
{
    /** @return array{valid: bool, subject: ?string, error: ?string} */
    public function verify(DOMDocument $doc): array
    {
        try {
            $dsig = new XMLSecurityDSig;
            $firma = $dsig->locateSignature($doc);

            if ($firma === null) {
                return ['valid' => false, 'subject' => null, 'error' => 'El documento no está firmado.'];
            }

            $dsig->canonicalizeSignedInfo();

            if (! $dsig->validateReference()) {
                return ['valid' => false, 'subject' => null, 'error' => 'El contenido no coincide con la firma.'];
            }

            $clave = $dsig->locateKey();
            $x509 = $firma->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'X509Certificate')->item(0)?->textContent;

            if ($clave === null || $x509 === null || trim($x509) === '') {
                return ['valid' => false, 'subject' => null, 'error' => 'La firma no trae el certificado.'];
            }

            $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split((string) preg_replace('/\s+/', '', $x509), 64, "\n")."-----END CERTIFICATE-----\n";
            $clave->loadKey($pem, false, true);

            if ($dsig->verify($clave) !== 1) {
                return ['valid' => false, 'subject' => null, 'error' => 'La firma no corresponde al certificado.'];
            }

            $info = openssl_x509_parse($pem) ?: [];

            return ['valid' => true, 'subject' => is_array($info['subject'] ?? null) ? $this->subject($info['subject']) : null, 'error' => null];
        } catch (Throwable $e) {
            return ['valid' => false, 'subject' => null, 'error' => 'Firma no válida: '.$e->getMessage()];
        }
    }

    /** @param array<string, string|list<string>> $s */
    private function subject(array $s): string
    {
        return implode(', ', array_map(fn ($k, $v) => $k.'='.(is_array($v) ? implode('/', $v) : $v), array_keys($s), $s));
    }
}
