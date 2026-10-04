<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Ncf;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoiceAuditLog;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use App\Modules\ElectronicInvoicing\Providers\ProviderOutcome;
use App\Modules\ElectronicInvoicing\Providers\ProviderResolver;
use App\Modules\ElectronicInvoicing\Providers\ProviderResult;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Signature\XmlSigner;
use App\Modules\ElectronicInvoicing\Xml\SafeXml;
use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use App\Modules\ElectronicInvoicing\Xml\XmlValidator;
use Carbon\CarbonImmutable;
use DOMDocument;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Anulación de e-NCF NO usados ante la DGII (ANECF) [DT pp.34–36; anecf.xsd].
 *
 * Solo la COLA sin usar de una secuencia: del próximo número al final del rango. Es el caso común
 * (cerrar un rango que ya no se usará) y garantiza que no se anula nada emitido: lo emitido está
 * siempre por debajo de `next_number`. La secuencia se recorta SOLO si la DGII lo procesa; la
 * anulación queda en la bitácora con la respuesta tal cual.
 */
final class RangeVoidService
{
    public function __construct(
        private readonly ElectronicNcfService $ncf,
        private readonly XmlSigner $signer,
        private readonly CertificateVault $certificates,
        private readonly SchemaRegistry $schemas,
        private readonly XmlValidator $validator,
        private readonly ProviderResolver $providers,
    ) {}

    public function voidUnused(ElectronicNcfSequence $seq, ?int $userId = null): ProviderResult
    {
        $desde = (int) $seq->next_number;
        $hasta = (int) $seq->range_to;

        if ($desde > $hasta) {
            throw new DomainException('Esta secuencia no tiene números sin usar.');
        }

        $company = Company::query()->findOrFail($seq->company_id);
        $settings = ElectronicInvoicingSettings::paraEmpresa($company);
        $rnc = preg_replace('/\D/', '', (string) $settings->tax_id);
        $encfDesde = $this->ncf->format($seq->ecf_type, $desde);
        $encfHasta = $this->ncf->format($seq->ecf_type, $hasta);
        $cantidad = (string) ($hasta - $desde + 1);

        $xml = $this->anecf($company, (string) $rnc, $seq, $encfDesde, $encfHasta, $cantidad);
        $ruta = sprintf('ecf/%d/%s/anulaciones/%s-%s-%s.xml', $company->id, $seq->environment->value, now()->format('Ymd-His'), $encfDesde, $encfHasta);
        CertificateVault::disk()->put($ruta, $xml, ['throw' => true]);

        $r = $this->providers->for($settings)->voidRange($company, $seq->environment, $xml, $rnc.$encfDesde.'.xml');

        DB::transaction(function () use ($seq, $r, $desde, $encfDesde, $encfHasta, $cantidad, $ruta, $userId): void {
            if ($r->outcome === ProviderOutcome::Accepted) {
                // La cola deja de existir: el rango termina justo antes del primer anulado.
                $seq->forceFill(['range_to' => $desde - 1, 'is_active' => false])->save();
            }

            ElectronicInvoiceAuditLog::create([
                'company_id' => $seq->company_id,
                'action' => $r->outcome === ProviderOutcome::Accepted ? 'Rango de e-NCF anulado' : 'Anulación de rango no procesada',
                'user_id' => $userId,
                'details' => [
                    'desde' => $encfDesde, 'hasta' => $encfHasta, 'cantidad' => $cantidad, 'xml' => $ruta,
                    'codigo' => $r->code, 'respuesta' => $r->summary(),
                ],
            ]);
        });

        return $r;
    }

    private function anecf(Company $company, string $rnc, ElectronicNcfSequence $seq, string $desde, string $hasta, string $cantidad): string
    {
        $d = new DOMDocument('1.0', 'utf-8');
        $raiz = $d->appendChild($d->createElement('ANECF'));
        $fecha = CarbonImmutable::now((string) config('ecf.formats.signature_utc_offset', '-04:00'))->format((string) config('ecf.formats.datetime', 'd-m-Y H:i:s'));

        $enc = $raiz->appendChild($d->createElement('Encabezado'));
        foreach (['Version' => '1.0', 'RncEmisor' => $rnc, 'CantidadeNCFAnulados' => $cantidad, 'FechaHoraAnulacioneNCF' => $fecha] as $k => $v) {
            $enc->appendChild($d->createElement($k))->appendChild($d->createTextNode($v));
        }

        $anulacion = $raiz->appendChild($d->createElement('DetalleAnulacion'))->appendChild($d->createElement('Anulacion'));
        $anulacion->appendChild($d->createElement('NoLinea', '1'));
        $anulacion->appendChild($d->createElement('TipoeCF', (string) $seq->ecf_type->value));
        $secuencias = $anulacion->appendChild($d->createElement('TablaRangoSecuenciasAnuladaseNCF'))->appendChild($d->createElement('Secuencias'));
        $secuencias->appendChild($d->createElement('SecuenciaeNCFDesde', $desde));
        $secuencias->appendChild($d->createElement('SecuenciaeNCFHasta', $hasta));
        $anulacion->appendChild($d->createElement('CantidadeNCFAnulados', $cantidad));

        $firmado = $this->signer->sign($d, $this->certificates->load($company), now(), writeSignatureDate: false)->xml;
        $errores = $this->validator->validate(SafeXml::load($firmado), $this->schemas->validationPath('anecf.xsd'));

        if ($errores !== []) {
            throw new RuntimeException('La anulación no cumple su esquema: '.$errores[0]->message);
        }

        return $firmado;
    }
}
