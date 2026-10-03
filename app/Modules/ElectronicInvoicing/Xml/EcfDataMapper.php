<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Xml;

use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfLine;
use App\Modules\ElectronicInvoicing\Domain\EcfParty;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use App\Modules\ElectronicInvoicing\Tax\TaxResult;
use Carbon\CarbonInterface;

/**
 * Convierte el documento canónico en el árbol de datos con los nombres oficiales del XSD.
 *
 * Un solo mapeador para todos los tipos: lo que es propio de un tipo (vencimiento de secuencia,
 * tipo de ingresos, etc.) se escribe solo si el XSD de ESE tipo tiene el elemento. Las condiciones
 * («condicional a que exista ítem gravado…») son las del Formato e-CF [FMT], citadas en cada bloque.
 *
 * Tipos cubiertos por ahora: los campos comunes del encabezado, emisor, comprador, totales de ITBIS y
 * detalle. Referencias a comprobantes modificados (33/34), retenciones (41/47), impuestos adicionales y
 * otra moneda se añaden con esos tipos.
 */
final class EcfDataMapper
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly XsdTree $xsd,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(EcfDocument $doc, TaxResult $tax): array
    {
        $esquema = $this->xsd->root($this->schemas->pathForType($doc->type));
        $tiene = fn (string $ruta): bool => $this->tiene($esquema, $ruta);

        $hayGravado = $this->hay($doc, fn (BillingIndicator $i): bool => $i->isTaxed());

        $idDoc = [
            'TipoeCF' => $doc->type->value,
            'eNCF' => $doc->encf,
            'FechaVencimientoSecuencia' => $tiene('Encabezado.IdDoc.FechaVencimientoSecuencia') ? $this->fecha($doc->sequenceExpiresAt) : null,
            // [FMT encabezado, campo 5] Nota de crédito: 1 si se emite pasados N días del e-CF afectado.
            'IndicadorNotaCredito' => $doc->reference !== null && $tiene('Encabezado.IdDoc.IndicadorNotaCredito')
                ? ($doc->reference->modifiedDate->copy()->startOfDay()->diffInDays($doc->issueDate->copy()->startOfDay()) > (int) config('ecf.credit_note_itbis_days', 30) ? 1 : 0)
                : null,
            // [FMT campo 7] Condicional a que haya ítems gravados.
            'IndicadorMontoGravado' => $hayGravado && $tiene('Encabezado.IdDoc.IndicadorMontoGravado') ? ($doc->pricesIncludeTax ? 1 : 0) : null,
            'TipoIngresos' => $tiene('Encabezado.IdDoc.TipoIngresos') ? $doc->incomeType : null,
            'TipoPago' => $tiene('Encabezado.IdDoc.TipoPago') ? $doc->paymentType : null,
            // [FMT campo 10] Solo para facturas a crédito.
            'FechaLimitePago' => $doc->paymentType === 2 && $tiene('Encabezado.IdDoc.FechaLimitePago') ? $this->fecha($doc->paymentDueDate) : null,
            'TablaFormasPago' => $doc->paymentForms !== [] && $tiene('Encabezado.IdDoc.TablaFormasPago')
                ? ['FormaDePago' => array_map(fn (array $p): array => ['FormaPago' => $p['form'], 'MontoPago' => $p['amount']], $doc->paymentForms)]
                : null,
        ];

        return [
            'Encabezado' => [
                'Version' => (string) config('ecf.spec.version', '1.0'),
                'IdDoc' => $idDoc,
                'Emisor' => $this->emisor($doc->emitter, $doc->issueDate),
                'Comprador' => $tiene('Encabezado.Comprador') ? $this->comprador($doc->buyer) : null,
                'Totales' => $this->totales($doc, $tax, $tiene),
            ],
            'DetallesItems' => [
                'Item' => array_map(
                    fn (EcfLine $l, int $i): array => $this->item($l, $i + 1, $tax->lineAmounts[$i], $esquema->child('DetallesItems')?->child('Item')),
                    $doc->lines,
                    array_keys($doc->lines),
                ),
            ],
            // [FMT sección F] Comprobante modificado (notas 33/34) o reemplazado (contingencia).
            'InformacionReferencia' => $doc->reference !== null && $tiene('InformacionReferencia') ? [
                'NCFModificado' => $doc->reference->modifiedNcf,
                'FechaNCFModificado' => $this->fecha($doc->reference->modifiedDate),
                'CodigoModificacion' => $doc->reference->code,
                'RazonModificacion' => $doc->reference->reason !== null && $doc->reference->reason !== '' ? mb_substr($doc->reference->reason, 0, 90) : null,
            ] : null,
        ];
    }

    /**
     * Resumen de la factura de consumo (RFCE) [DT p.15]: por definición es un SUBCONJUNTO de los
     * datos del e-CF 32 (encabezado y totales, sin detalle) más el código de seguridad del 32 firmado.
     * Por eso aquí sí se recorta al esquema: se parte del árbol completo del 32 y se queda solo lo
     * que el XSD del RFCE tiene.
     *
     * @return array<string, mixed>
     */
    public function toRfceArray(EcfDocument $doc, TaxResult $tax, string $securityCode): array
    {
        $rfce = $this->xsd->root($this->schemas->validationPath((string) config('ecf.schemas.rfce')));
        $completo = $this->toArray($doc, $tax);

        // En el XSD del RFCE, CodigoSeguridadeCF es el último hijo del Encabezado (tras Totales).
        return [
            'Encabezado' => [
                ...$this->recortar($completo['Encabezado'], $rfce->child('Encabezado')),
                'CodigoSeguridadeCF' => $securityCode,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function recortar(array $datos, ?XsdNode $nodo): array
    {
        if ($nodo === null) {
            return [];
        }

        $resultado = [];

        foreach ($datos as $clave => $valor) {
            $hijo = $nodo->child((string) $clave);

            if ($hijo === null || $valor === null) {
                continue;
            }

            $resultado[$clave] = match (true) {
                ! is_array($valor) || $hijo->children === [] => $valor,
                $hijo->isRepeatable() => array_map(fn ($o) => is_array($o) ? $this->recortar($o, $hijo) : $o, $valor),
                default => $this->recortar($valor, $hijo),
            };
        }

        return $resultado;
    }

    /** @return array<string, mixed> */
    private function emisor(EcfParty $p, CarbonInterface $fecha): array
    {
        return [
            'RNCEmisor' => $this->digitos($p->taxId),
            'RazonSocialEmisor' => $p->legalName,
            'NombreComercial' => $p->tradeName,
            'DireccionEmisor' => $p->address,
            'Municipio' => $p->municipality,
            'Provincia' => $p->province,
            'CorreoEmisor' => $p->email,
            'FechaEmision' => $this->fecha($fecha),
        ];
    }

    /** @return array<string, mixed> */
    private function comprador(?EcfParty $p): array
    {
        if ($p === null) {
            return [];
        }

        return [
            'RNCComprador' => $this->digitos($p->taxId),
            'IdentificadorExtranjero' => $p->foreignId !== null && $p->foreignId !== '' ? mb_substr($p->foreignId, 0, 20) : null,
            'RazonSocialComprador' => $p->legalName,
            'CorreoComprador' => $p->email,
            'DireccionComprador' => $p->address,
            'MunicipioComprador' => $p->municipality,
            'ProvinciaComprador' => $p->province,
        ];
    }

    /**
     * [FMT sección A «Totales», campos 92–110]: cada monto y tasa se informa solo si existe al menos
     * un ítem de ese indicador.
     *
     * @return array<string, mixed>
     */
    private function totales(EcfDocument $doc, TaxResult $tax, callable $tiene): array
    {
        $hay = fn (BillingIndicator $i): bool => $this->hay($doc, fn (BillingIndicator $x): bool => $x === $i);
        $hayGravado = $this->hay($doc, fn (BillingIndicator $i): bool => $i->isTaxed());

        $t = [
            'MontoGravadoTotal' => $hayGravado ? $tax->taxedTotal : null,
            'MontoGravadoI1' => $hay(BillingIndicator::Itbis1) ? $tax->taxedBase[1] : null,
            'MontoGravadoI2' => $hay(BillingIndicator::Itbis2) ? $tax->taxedBase[2] : null,
            'MontoGravadoI3' => $hay(BillingIndicator::Itbis3) ? $tax->taxedBase[3] : null,
            'MontoExento' => $hay(BillingIndicator::Exento) ? $tax->exempt : null,
            'ITBIS1' => $hay(BillingIndicator::Itbis1) ? BillingIndicator::Itbis1->rate() : null,
            'ITBIS2' => $hay(BillingIndicator::Itbis2) ? BillingIndicator::Itbis2->rate() : null,
            'ITBIS3' => $hay(BillingIndicator::Itbis3) ? BillingIndicator::Itbis3->rate() : null,
            'TotalITBIS' => $hayGravado ? $tax->itbisTotal : null,
            'TotalITBIS1' => $hay(BillingIndicator::Itbis1) ? $tax->itbis[1] : null,
            'TotalITBIS2' => $hay(BillingIndicator::Itbis2) ? $tax->itbis[2] : null,
            'TotalITBIS3' => $hay(BillingIndicator::Itbis3) ? $tax->itbis[3] : null,
            'MontoTotal' => $tax->total,
            'MontoNoFacturable' => $hay(BillingIndicator::NoFacturable) ? $this->noFacturable($doc, $tax) : null,
            'TotalITBISRetenido' => $this->sumaRetencion($doc, 'itbisWithheld'),
            'TotalISRRetencion' => $this->sumaRetencion($doc, 'isrWithheld'),
        ];

        // Un tipo puede no tener alguno de estos (p. ej. los gastos menores): se omite, no se inventa.
        return array_filter($t, fn ($v, string $k): bool => $v !== null && $tiene("Encabezado.Totales.{$k}"), ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<string, mixed> */
    private function item(EcfLine $l, int $numero, string $monto, ?XsdNode $esquemaItem): array
    {
        $descuento = bccomp($l->discount, '0', 2) > 0 ? bcadd($l->discount, '0', 2) : null;

        return [
            'NumeroLinea' => $numero,
            'IndicadorFacturacion' => $l->indicator->value,
            'Retencion' => $this->retencion($l, $esquemaItem?->child('Retencion')),
            // [XSD AlfNum80Type] El nombre se recorta, no se rechaza: el detalle completo cabe en DescripcionItem.
            'NombreItem' => mb_substr($l->name, 0, 80),
            'IndicadorBienoServicio' => $l->isService ? 2 : 1,
            'DescripcionItem' => $l->description !== null && $l->description !== '' ? mb_substr($l->description, 0, 1000) : null,
            'CantidadItem' => $l->quantity,
            'PrecioUnitarioItem' => $this->precio($l->unitPrice),
            // [FMT ítem, campos 26–28] Con descuento van el monto y su tabla de distribución.
            'DescuentoMonto' => $descuento,
            'TablaSubDescuento' => $descuento !== null
                ? ['SubDescuento' => [['TipoSubDescuento' => '$', 'MontoSubDescuento' => $descuento]]]
                : null,
            'MontoItem' => $monto,
        ];
    }

    /**
     * [FMT sección B, campos 5–7] Retención del ítem. Donde el XSD la exige en todos los ítems
     * (41, 47) se escribe siempre; si no, solo cuando la línea la tiene. Un monto que el XSD exige y
     * la línea no trae se declara en 0, que el Formato admite («Valor numérico… 0»).
     *
     * @return array<string, mixed>|null
     */
    private function retencion(EcfLine $l, ?XsdNode $nodo): ?array
    {
        if ($nodo === null || (! $l->hasRetention() && $nodo->minOccurs === 0)) {
            return null;
        }

        $monto = function (string $campo, ?string $valor) use ($nodo): ?string {
            $hijo = $nodo->child($campo);

            if ($hijo === null) {
                return null;
            }

            return $valor !== null ? bcadd($valor, '0', 2) : ($hijo->minOccurs > 0 ? '0.00' : null);
        };

        return [
            'IndicadorAgenteRetencionoPercepcion' => (int) config('ecf.retention_indicator', 1),
            'MontoITBISRetenido' => $monto('MontoITBISRetenido', $l->itbisWithheld),
            'MontoISRRetenido' => $monto('MontoISRRetenido', $l->isrWithheld),
        ];
    }

    /** [FMT campos 116–117] Suma de una retención de todas las líneas, o null si ninguna la tiene. */
    private function sumaRetencion(EcfDocument $doc, string $campo): ?string
    {
        $suma = null;

        foreach ($doc->lines as $l) {
            if ($l->{$campo} !== null) {
                $suma = bcadd($suma ?? '0', $l->{$campo}, 2);
            }
        }

        return $suma;
    }

    private function noFacturable(EcfDocument $doc, TaxResult $tax): string
    {
        $suma = '0.00';

        foreach ($doc->lines as $i => $l) {
            if ($l->indicator === BillingIndicator::NoFacturable) {
                $suma = bcadd($suma, $tax->lineAmounts[$i], 2);
            }
        }

        return $suma;
    }

    private function hay(EcfDocument $doc, callable $condicion): bool
    {
        foreach ($doc->lines as $l) {
            if ($condicion($l->indicator)) {
                return true;
            }
        }

        return false;
    }

    private function tiene(XsdNode $raiz, string $ruta): bool
    {
        $nodo = $raiz;

        foreach (explode('.', $ruta) as $nombre) {
            $nodo = $nodo->child($nombre);

            if ($nodo === null) {
                return false;
            }
        }

        return true;
    }

    private function fecha(?CarbonInterface $fecha): ?string
    {
        return $fecha?->format((string) config('ecf.formats.date', 'd-m-Y'));
    }

    private function digitos(?string $valor): ?string
    {
        $d = preg_replace('/\D/', '', (string) $valor);

        return $d === '' ? null : $d;
    }

    /** Precio unitario: hasta 4 decimales [XSD Decimal20D1or4]. Sin ceros de relleno innecesarios. */
    private function precio(string $valor): string
    {
        $cuatro = bcadd($valor, '0', 4);
        $limpio = rtrim(rtrim($cuatro, '0'), '.');

        return str_contains($limpio, '.') ? $limpio : $limpio.'.00';
    }
}
