<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe\Digifact;

use App\Modules\ElectronicInvoicing\Xml\SafeXml;
use Carbon\CarbonImmutable;
use DOMNode;
use DOMXPath;

/**
 * Del XML e-CF de la DGII (el `original` sin firmar que genera BMIA) al NUC JSON de Digifact.
 *
 * Fuente: NUC JSON V1.0.7 (https://documentacion.digifact.com/do/nuc/json.md) y sus ejemplos oficiales
 * 31, 32, 33 y 34 (copiados en tests/Fixtures/digifact), consultados el 2026-10-05. Se respeta su
 * forma AL PIE DE LA LETRA, erratas incluidas: la API distingue mayúsculas y los nombres
 * `AdditionlInfo` (vendedor y comprador) y `AditionalData`/`AditionalInfo` (información adicional del
 * documento) se escriben así en su especificación y en sus ejemplos.
 *
 * Se parte del XML de la DGII y no del documento canónico porque ese XML ya pasó la validación del XSD
 * oficial: Digifact recibe exactamente los mismos importes que la DGII, sin recalcular nada.
 *
 * Lo que sus ejemplos no muestran (retenciones, montos no facturables, tipos distintos de 31–34) NO se
 * inventa: se rechaza con `DigifactUnsupportedDocument` y la factura sale por el respaldo, si lo hay.
 */
final class DigifactNucMapper
{
    /** Tipos con ejemplo oficial de Digifact. */
    public const TIPOS = ['31', '32', '33', '34'];

    /** @return array<string, mixed> */
    public function fromDgiiXml(string $xml): array
    {
        $x = new DOMXPath(SafeXml::load($xml));
        $v = fn (string $ruta, ?DOMNode $en = null): string => trim((string) $x->evaluate('string('.$this->ruta($ruta).')', $en));

        $tipo = $v('Encabezado/IdDoc/TipoeCF');
        $encf = $v('Encabezado/IdDoc/eNCF');

        if (! in_array($tipo, self::TIPOS, true)) {
            throw new DigifactUnsupportedDocument("El conector de Digifact todavía no envía comprobantes tipo {$tipo}.");
        }

        if ($x->query($this->ruta('DetallesItems/Item/Retencion'))->length > 0 || $v('Encabezado/Totales/MontoNoFacturable') !== '') {
            throw new DigifactUnsupportedDocument('El conector de Digifact todavía no envía facturas con retenciones ni montos no facturables.');
        }

        return [
            'Version' => '1.0',
            'CountryCode' => 'DO',
            'Header' => [
                'DocType' => $tipo,
                'IssuedDateTime' => $this->fechaHora($v('Encabezado/Emisor/FechaEmision')),
                'AdditionalIssueDocInfo' => $this->infos([
                    // [NUC AI02] Secuencia: los 10 dígitos del e-NCF. La numeración la pone BMIA.
                    'Secuencia' => substr($encf, 3),
                    'FechaVencimientoSecuencia' => $this->fecha($v('Encabezado/IdDoc/FechaVencimientoSecuencia')),
                    'IndicadorNotaCredito' => $v('Encabezado/IdDoc/IndicadorNotaCredito'),
                    'IndicadorMontoGravado' => $v('Encabezado/IdDoc/IndicadorMontoGravado'),
                    'TipoIngresos' => $v('Encabezado/IdDoc/TipoIngresos'),
                    'TipoPago' => $v('Encabezado/IdDoc/TipoPago'),
                    'FechaLimitePago' => $this->fecha($v('Encabezado/IdDoc/FechaLimitePago')),
                ]),
            ],
            'Seller' => $this->vendedor($v),
            'Buyer' => $this->comprador($v),
            'Items' => array_map(fn (DOMNode $item): array => $this->item($v, $item), iterator_to_array($x->query($this->ruta('DetallesItems/Item')))),
            'Totals' => $this->totales($v, $x->query($this->ruta('DetallesItems/Item'))->length),
            'Payments' => array_map(
                fn (DOMNode $p): array => ['Code' => $v('FormaPago', $p), 'Amount' => $v('MontoPago', $p)],
                iterator_to_array($x->query($this->ruta('Encabezado/IdDoc/TablaFormasPago/FormaDePago'))),
            ),
            'AdditionalDocumentInfo' => ['AdditionalInfo' => [$this->informacionAdicional($v)]],
        ];
    }

    /** El e-NCF del XML, para comprobar que Digifact certificó ese número y no otro. */
    public function encf(string $xml): string
    {
        return trim((string) (new DOMXPath(SafeXml::load($xml)))->evaluate('string('.$this->ruta('Encabezado/IdDoc/eNCF').')'));
    }

    /** @return array<string, mixed> */
    private function vendedor(callable $v): array
    {
        $direccion = $this->direccion($v('Encabezado/Emisor/DireccionEmisor'), $v('Encabezado/Emisor/Municipio'), $v('Encabezado/Emisor/Provincia'));

        return array_filter([
            'TaxID' => $v('Encabezado/Emisor/RNCEmisor'),
            'Name' => $v('Encabezado/Emisor/RazonSocialEmisor'),
            'Contact' => $this->contacto($v('Encabezado/Emisor/CorreoEmisor')),
            'AdditionlInfo' => $this->infos(['NombreComercial' => $v('Encabezado/Emisor/NombreComercial')]),
            'AddressInfo' => $direccion,
            'BranchInfo' => array_filter([
                'Code' => null,
                'Name' => (string) config('ecf_psfe.digifact.branch', '1'),
                'AddressInfo' => $direccion,
            ], fn ($valor, string $k): bool => $k === 'Code' || $valor !== null, ARRAY_FILTER_USE_BOTH),
        ], fn ($valor): bool => $valor !== null);
    }

    /**
     * Sin comprador (consumo de menos de RD$250.000) va como en su ejemplo 32: RNC vacío y
     * «Consumidor Final». Un extranjero lleva su identificación con `TaxIDType` EXTRANJERO [NUC C03].
     *
     * @return array<string, mixed>
     */
    private function comprador(callable $v): array
    {
        $rnc = $v('Encabezado/Comprador/RNCComprador');
        $extranjero = $v('Encabezado/Comprador/IdentificadorExtranjero');
        $nombre = $v('Encabezado/Comprador/RazonSocialComprador');

        if ($rnc === '' && $extranjero === '' && $nombre === '') {
            return ['TaxID' => '', 'Name' => 'Consumidor Final'];
        }

        return array_filter([
            'TaxID' => $rnc !== '' ? $rnc : $extranjero,
            'TaxIDType' => $rnc === '' && $extranjero !== '' ? 'EXTRANJERO' : null,
            'Name' => $nombre !== '' ? $nombre : 'Consumidor Final',
            'Contact' => $this->contacto($v('Encabezado/Comprador/CorreoComprador')),
            'AddressInfo' => $this->direccion($v('Encabezado/Comprador/DireccionComprador'), $v('Encabezado/Comprador/MunicipioComprador'), $v('Encabezado/Comprador/ProvinciaComprador')),
        ], fn ($valor): bool => $valor !== null);
    }

    /** @return array<string, mixed> */
    private function item(callable $v, DOMNode $item): array
    {
        $descuento = $v('DescuentoMonto', $item);

        return array_filter([
            'Type' => $v('IndicadorBienoServicio', $item),
            'Description' => $v('NombreItem', $item),
            'Qty' => $v('CantidadItem', $item),
            'Price' => $v('PrecioUnitarioItem', $item),
            'Discounts' => $descuento !== '' ? ['Discount' => [['Code' => '$', 'Amount' => $descuento]]] : null,
            'Totals' => ['TotalItem' => $v('MontoItem', $item)],
            'AdditionalInfo' => $this->infos([
                'IndicadorFacturacion' => $v('IndicadorFacturacion', $item),
                'DescripcionItem' => $v('DescripcionItem', $item),
            ]),
        ], fn ($valor): bool => $valor !== null);
    }

    /**
     * Totales e impuestos por tasa [NUC F]. El código EXENTO figura en la especificación (F0411) pero no
     * en sus ejemplos: va con tasa e importe 0 (pendiente de confirmar con Digifact).
     *
     * @return array<string, mixed>
     */
    private function totales(callable $v, int $lineas): array
    {
        $impuestos = [];

        foreach ([1, 2, 3] as $n) {
            $base = $v("Encabezado/Totales/MontoGravadoI{$n}");

            if ($base !== '') {
                $impuestos[] = [
                    'Code' => "ITBIS{$n}",
                    'TaxableAmount' => $base,
                    'Rate' => number_format((float) $v("Encabezado/Totales/ITBIS{$n}"), 2, '.', ''),
                    'Amount' => $v("Encabezado/Totales/TotalITBIS{$n}") ?: '0.00',
                ];
            }
        }

        if (($exento = $v('Encabezado/Totales/MontoExento')) !== '') {
            $impuestos[] = ['Code' => 'EXENTO', 'TaxableAmount' => $exento, 'Rate' => '0.00', 'Amount' => '0.00'];
        }

        return array_filter([
            'QtyItems' => $lineas,
            'TotalTaxableAmount' => $v('Encabezado/Totales/MontoGravadoTotal') ?: null,
            'TotalTaxes' => $impuestos !== [] ? ['TotalTax' => $impuestos] : null,
            'GrandTotal' => ['InvoiceTotal' => $v('Encabezado/Totales/MontoTotal')],
        ], fn ($valor): bool => $valor !== null);
    }

    /**
     * Notas de crédito y débito (33/34): el comprobante modificado, como en sus ejemplos 33 y 34. Sin
     * referencia, el bloque obligatorio [NUC H01/H02] va vacío como en ellos (`AditionalInfo` null).
     *
     * @return array<string, mixed>
     */
    private function informacionAdicional(callable $v): array
    {
        $modificado = $v('InformacionReferencia/NCFModificado');

        if ($modificado === '') {
            return ['AditionalData' => null, 'AditionalInfo' => null];
        }

        return [
            'AditionalData' => ['Data' => [[
                'Info' => $this->infos([
                    'NCFModificado' => $modificado,
                    'FechaNCFModificado' => $this->fecha($v('InformacionReferencia/FechaNCFModificado')),
                    'CodigoModificacion' => $v('InformacionReferencia/CodigoModificacion'),
                    'RazonModificacion' => $v('InformacionReferencia/RazonModificacion'),
                ]),
                'Name' => 'INFORMACION_REFERENCIA',
            ]]],
            'AditionalInfo' => null,
        ];
    }

    /**
     * Pares `{Name, Data, Value}`, la forma de todas sus listas de datos adicionales. Los vacíos no
     * se mandan.
     *
     * @param  array<string, string|null>  $datos
     * @return list<array{Name: string, Data: null, Value: string}>
     */
    private function infos(array $datos): array
    {
        $lista = [];

        foreach ($datos as $nombre => $valor) {
            if ($valor !== null && $valor !== '') {
                $lista[] = ['Name' => $nombre, 'Data' => null, 'Value' => $valor];
            }
        }

        return $lista;
    }

    /** @return array<string, mixed>|null */
    private function contacto(string $correo): ?array
    {
        return $correo !== '' ? ['EmailList' => ['Email' => [$correo]]] : null;
    }

    /** @return array<string, string|null>|null */
    private function direccion(string $direccion, string $municipio, string $provincia): ?array
    {
        if ($direccion === '') {
            return null;
        }

        return array_filter([
            'Address' => $direccion,
            'District' => $municipio !== '' ? $municipio : null,
            'State' => $provincia !== '' ? $provincia : null,
            'Country' => 'DO',
        ], fn ($valor): bool => $valor !== null);
    }

    /** dd-mm-aaaa (formato DGII) → aaaa-mm-dd (formato NUC). */
    private function fecha(string $dgii): ?string
    {
        return $dgii === '' ? null : CarbonImmutable::createFromFormat('d-m-Y', $dgii)->format('Y-m-d');
    }

    /**
     * [NUC A03] Fecha y hora con zona. La DGII solo lleva la fecha: si es hoy va la hora actual de
     * República Dominicana; si no, el mediodía de ese día.
     */
    private function fechaHora(string $dgii): string
    {
        $zona = (string) config('ecf.formats.signature_utc_offset', '-04:00');
        $ahora = CarbonImmutable::now()->setTimezone($zona);
        $dia = CarbonImmutable::createFromFormat('d-m-Y H:i:s', $dgii.' 12:00:00', $zona);

        return ($dia->isSameDay($ahora) ? $ahora : $dia)->format('Y-m-d\TH:i:sP');
    }

    /** Una ruta relativa a la raíz del e-CF, sin depender del prefijo de espacio de nombres. */
    private function ruta(string $ruta): string
    {
        $pasos = implode('/', array_map(fn (string $p): string => "*[local-name()='{$p}']", explode('/', $ruta)));

        return str_starts_with($ruta, 'Encabezado') || str_starts_with($ruta, 'DetallesItems') || str_starts_with($ruta, 'InformacionReferencia')
            ? '/*/'.$pasos
            : $pasos;
    }
}
