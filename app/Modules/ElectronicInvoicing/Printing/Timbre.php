<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Printing;

use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use App\Modules\ElectronicInvoicing\Storage\FiscalDocumentStore;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Lo que la representación impresa (RI) de un e-CF tiene que llevar, listo para pintar.
 *
 * [DT pp.40–43] Timbre (QR) que lleva a la consulta de la DGII, distinto según el envío:
 *   · e-CF por recepción:  {host ecf}/{ambiente}/consultatimbre?rncemisor&rnccomprador&encf&fechaemision
 *                          &montototal&fechafirma&codigoseguridad
 *   · 32 bajo RD$250.000 (RFCE): {host fc}/{ambiente}/consultatimbrefc?rncemisor&encf&montototal
 *                          &codigoseguridad
 * Los valores se leen del XML FIRMADO tal como se enviaron (no de la base): el timbre tiene que
 * coincidir exactamente con lo que recibió la DGII. Nombres de parámetro en minúscula como en el
 * ejemplo oficial (los servicios no distinguen mayúsculas [DT bitácora 18-05-2023]); valores
 * codificados como URL (el ejemplo codifica el espacio de la fecha de firma como %20).
 *
 * [IT §18] Además: el tipo en palabras, el e-NCF, el vencimiento de la secuencia, el código de
 * seguridad bajo el QR, y la leyenda de contingencia si se emitió en contingencia [IT §19].
 */
final class Timbre
{
    public function __construct(
        private readonly FiscalDocumentStore $files,
        private readonly QrCode $qr,
    ) {}

    /**
     * @return array{tipo: string, encf: string, vence: ?string, fecha_firma: ?string, codigo: ?string,
     *               url: string, qr: string, qr_version: int, contingencia: ?string, ambiente: string, fiscal: bool}
     */
    public function for(ElectronicInvoice $ecf): array
    {
        $archivo = $ecf->file('firmado') ?? throw new RuntimeException("El e-CF {$ecf->e_ncf} todavía no está firmado.");
        $xml = new DOMDocument;
        $xml->loadXML($this->files->get($archivo), LIBXML_NONET);
        $x = new DOMXPath($xml);
        $v = fn (string $tag): string => trim((string) $x->evaluate("string(//*[local-name()='{$tag}'][1])"));

        $parametros = $ecf->sends_summary
            ? [
                'rncemisor' => $v('RNCEmisor'),
                'encf' => $ecf->e_ncf,
                'montototal' => $v('MontoTotal'),
                'codigoseguridad' => (string) $ecf->security_code,
            ]
            : [
                'rncemisor' => $v('RNCEmisor'),
                'rnccomprador' => $v('RNCComprador'),
                'encf' => $ecf->e_ncf,
                'fechaemision' => $v('FechaEmision'),
                'montototal' => $v('MontoTotal'),
                'fechafirma' => $v('FechaHoraFirma'),
                'codigoseguridad' => (string) $ecf->security_code,
            ];

        $servicio = (array) config('ecf.services.'.($ecf->sends_summary ? 'stamp_fc' : 'stamp'));
        $host = rtrim((string) config('ecf.hosts.'.($servicio['host'] ?? 'ecf')), '/');
        $url = $host.'/'.$ecf->environment->segment().($servicio['path'] ?? '').'?'
            // Como el ejemplo oficial: el espacio va como %20 y los «:» de la hora quedan tal cual
            // («fechafirma=10-10-2020%2009:00:00»); «:» es válido en la consulta de una URL.
            .implode('&', array_map(fn (string $k, string $val): string => $k.'='.str_replace('%3A', ':', rawurlencode($val)), array_keys($parametros), $parametros));

        $qr = $this->qr->svg($url);
        $vence = $ecf->electronic_ncf_sequence_id !== null
            ? ElectronicNcfSequence::query()->withoutGlobalScopes()->find($ecf->electronic_ncf_sequence_id)?->expires_at?->format('d-m-Y')
            : null;

        return [
            'tipo' => $ecf->ecf_type->label(),
            'encf' => $ecf->e_ncf,
            'vence' => $vence,
            'fecha_firma' => $v('FechaHoraFirma') ?: null,
            'codigo' => $ecf->security_code,
            'url' => $url,
            'qr' => 'data:image/svg+xml;base64,'.base64_encode($qr['svg']),
            'qr_version' => $qr['version'],
            'contingencia' => $ecf->contingency_id !== null ? (string) config('ecf.contingency.legend') : null,
            'ambiente' => $ecf->environment->label(),
            'fiscal' => $ecf->environment->isFiscal(),
        ];
    }
}
