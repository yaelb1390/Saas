<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Printing;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Common\Version;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Exception\WriterException;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;

/**
 * QR del timbre en SVG (sin GD: producción no lo tiene; dompdf pinta SVG).
 *
 * [DT pp.40–42] «Se utilizará la versión 8 de código QR». HALLAZGO: la URL completa del timbre de un
 * e-CF ronda los 200 caracteres y la versión 8 en modo byte admite como máximo 192 (corrección L), así
 * que con frecuencia no cabe. Se intenta la versión 8 y, si no cabe, se usa la menor versión que la
 * contenga —nunca se recorta la URL—. Queda en `pending_verification.qr_version`.
 */
final class QrCode
{
    /** @return array{svg: string, version: int} */
    public function svg(string $contenido, int $tamano = 260): array
    {
        // Sin el prefijo ECI: algunos lectores no lo entienden y la URL es ASCII.
        try {
            $qr = Encoder::encode($contenido, ErrorCorrectionLevel::L(), Encoder::DEFAULT_BYTE_MODE_ENCODING, Version::getVersionForNumber(8), false);
        } catch (WriterException) {
            $qr = Encoder::encode($contenido, ErrorCorrectionLevel::L(), Encoder::DEFAULT_BYTE_MODE_ENCODING, null, false);
        }

        $svg = (new ImageRenderer(new RendererStyle($tamano, 1), new SvgImageBackEnd))->render($qr);

        return ['svg' => $svg, 'version' => $qr->getVersion()->getVersionNumber()];
    }
}
