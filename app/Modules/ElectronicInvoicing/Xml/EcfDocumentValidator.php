<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Xml;

use App\Modules\Billing\Support\TaxId;
use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Ncf\ElectronicNcfException;
use App\Modules\ElectronicInvoicing\Ncf\ElectronicNcfService;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use App\Modules\ElectronicInvoicing\Tax\TaxResult;

/**
 * Reglas de negocio que el XSD no puede comprobar: dígito verificador del RNC, que el e-NCF sea del
 * tipo correcto, que el comprador exista cuando el tipo lo exige, y que los totales cuadren.
 *
 * Los datos obligatorios por tipo salen del propio XSD (comprador con RNC en 31, 41 y 45); el resto,
 * del Formato e-CF. Mensajes en español claro, para el dueño del negocio.
 */
final class EcfDocumentValidator
{
    public function __construct(
        private readonly ElectronicNcfService $ncf,
        private readonly SchemaRegistry $schemas,
        private readonly XsdTree $xsd,
    ) {}

    /** @return list<ValidationError> */
    public function validate(EcfDocument $doc, ?TaxResult $tax = null): array
    {
        $errores = [];

        try {
            $partes = $this->ncf->parse($doc->encf);

            if ($partes['type'] !== $doc->type) {
                $errores[] = new ValidationError("El e-NCF {$doc->encf} no corresponde al tipo de comprobante {$doc->type->prefix()}.", 'eNCF');
            }
        } catch (ElectronicNcfException) {
            $errores[] = new ValidationError("«{$doc->encf}» no es un e-NCF válido: debe tener 13 posiciones (E + tipo + 10 dígitos).", 'eNCF');
        }

        if ($doc->emitter->taxId === null || TaxId::tryParse($doc->emitter->taxId) === null) {
            $errores[] = new ValidationError('El RNC del emisor no tiene un formato válido. Revísalo en Facturación Electrónica → datos fiscales.', 'RNCEmisor');
        }

        if (blank($doc->emitter->legalName)) {
            $errores[] = new ValidationError('Falta la razón social del emisor.', 'RazonSocialEmisor');
        }

        if (blank($doc->emitter->address)) {
            $errores[] = new ValidationError('Falta la dirección del emisor.', 'DireccionEmisor');
        }

        $comprador = $this->compradorExigido($doc->type);
        $rncComprador = $doc->buyer?->taxId;

        if ($rncComprador !== null && $rncComprador !== '' && TaxId::tryParse($rncComprador) === null) {
            $errores[] = new ValidationError('El RNC del comprador no tiene un formato válido.', 'RNCComprador');
        }

        if ($comprador['rnc'] && blank($rncComprador)) {
            $errores[] = new ValidationError("Un comprobante {$doc->type->prefix()} ({$doc->type->label()}) necesita el RNC o la cédula del comprador.", 'RNCComprador');
        }

        if ($comprador['razon'] && blank($doc->buyer?->legalName)) {
            $errores[] = new ValidationError("Un comprobante {$doc->type->prefix()} necesita la razón social del comprador.", 'RazonSocialComprador');
        }

        if ($doc->lines === []) {
            $errores[] = new ValidationError('No se puede generar el e-CF sin ninguna línea de detalle.', 'Item');
        }

        if (count($doc->lines) > 1000) {
            $errores[] = new ValidationError('Un e-CF admite como máximo 1.000 líneas de detalle.', 'Item');
        }

        $permitidos = ((array) config('ecf.allowed_indicators', []))[$doc->type->value] ?? null;

        foreach ($doc->lines as $i => $linea) {
            $n = $i + 1;

            if (preg_match('/^\d+(\.\d{1,2})?$/', $linea->quantity) !== 1 || bccomp($linea->quantity, '0', 2) <= 0) {
                $errores[] = new ValidationError("La cantidad de la línea {$n} debe ser mayor que cero y tener como máximo 2 decimales.", 'CantidadItem');
            }

            // [FMT notas 50 y 51] 43, 44 y 47 solo exentos; 46 solo a ITBIS tasa cero.
            if ($permitidos !== null && ! in_array($linea->indicator->value, $permitidos, true)) {
                $esperado = implode(' o ', array_map(fn (int $v): string => BillingIndicator::from($v)->label(), $permitidos));
                $errores[] = new ValidationError("En un comprobante {$doc->type->prefix()} cada ítem debe ir «{$esperado}» (regla de la DGII); la línea {$n} no.", 'IndicadorFacturacion');
            }

            // [FMT ítem campo 7] En el 41, el ISR retenido solo procede en servicios.
            if ($doc->type === EcfType::Compras && $linea->isrWithheld !== null && ! $linea->isService) {
                $errores[] = new ValidationError("La línea {$n} retiene ISR pero no es un servicio: en un comprobante de compras el ISR solo se retiene en servicios.", 'MontoISRRetenido');
            }

            if ($linea->hasRetention() && ! $this->schemaHas($doc->type, 'DetallesItems.Item.Retencion')) {
                $errores[] = new ValidationError("Un comprobante {$doc->type->prefix()} no lleva retenciones; quítalas de la línea {$n}.", 'Retencion');
            }
        }

        if ($this->schemaHas($doc->type, 'Encabezado.IdDoc.FechaVencimientoSecuencia', required: true) && $doc->sequenceExpiresAt === null) {
            $errores[] = new ValidationError("Un comprobante {$doc->type->prefix()} necesita la fecha de vencimiento de su secuencia.", 'FechaVencimientoSecuencia');
        }

        $errores = [...$errores, ...$this->referencia($doc, $tax)];

        if ($tax !== null && $doc->declaredTotal !== null) {
            $diferencia = ltrim(bcsub($doc->declaredTotal, $tax->total, 2), '-');

            // Hasta un céntimo es el redondeo de la fórmula oficial con ITBIS incluido (documentado);
            // más que eso es que el detalle no corresponde al documento.
            if (bccomp($diferencia, '0.01', 2) > 0) {
                $errores[] = new ValidationError("El total calculado ({$tax->total}) no coincide con el detalle del documento ({$doc->declaredTotal}).", 'MontoTotal');
            }
        }

        return $errores;
    }

    /**
     * [FMT sección F] El comprobante que se modifica: obligatorio donde el XSD lo exige (33/34).
     *
     * @return list<ValidationError>
     */
    private function referencia(EcfDocument $doc, ?TaxResult $tax): array
    {
        $ref = $doc->reference;

        if ($ref === null) {
            return $this->schemaHas($doc->type, 'InformacionReferencia', required: true)
                ? [new ValidationError("Una {$doc->type->label()} necesita el comprobante que modifica (e-NCF o NCF, fecha y motivo).", 'InformacionReferencia')]
                : [];
        }

        $errores = [];

        // [FMT campo 1] 11 (NCF en papel), 13 (e-NCF) o 19 posiciones.
        if (! in_array(strlen($ref->modifiedNcf), [11, 13, 19], true)) {
            $errores[] = new ValidationError("El comprobante modificado «{$ref->modifiedNcf}» no tiene una longitud válida (11, 13 o 19 posiciones).", 'NCFModificado');
        }

        if (! array_key_exists($ref->code, (array) config('ecf.codes.modification', []))) {
            $errores[] = new ValidationError('El código de modificación no es uno de los que define la DGII.', 'CodigoModificacion');
        }

        if ($ref->modifiedDate->greaterThan($doc->issueDate)) {
            $errores[] = new ValidationError('La fecha del comprobante modificado no puede ser posterior a la de la nota.', 'FechaNCFModificado');
        }

        // [FMT campo 110 d)] Las notas de crédito no pueden sumar más que el comprobante afectado.
        if ($doc->type === EcfType::NotaCredito && $tax !== null && $ref->modifiedTotal !== null) {
            $acumulado = bcadd($ref->alreadyCredited, $tax->total, 2);

            if (bccomp($acumulado, $ref->modifiedTotal, 2) > 0) {
                $errores[] = new ValidationError("Las notas de crédito ({$acumulado}) superarían el total del comprobante {$ref->modifiedNcf} ({$ref->modifiedTotal}).", 'MontoTotal');
            }
        }

        return $errores;
    }

    /** @return array{rnc: bool, razon: bool} */
    private function compradorExigido(EcfType $type): array
    {
        return [
            'rnc' => $this->schemaHas($type, 'Encabezado.Comprador.RNCComprador', required: true),
            'razon' => $this->schemaHas($type, 'Encabezado.Comprador.RazonSocialComprador', required: true),
        ];
    }

    private function schemaHas(EcfType $type, string $ruta, bool $required = false): bool
    {
        $nodo = $this->xsd->root($this->schemas->pathForType($type));

        foreach (explode('.', $ruta) as $nombre) {
            $nodo = $nodo->child($nombre);

            if ($nodo === null || ($required && $nodo->minOccurs === 0)) {
                return false;
            }
        }

        return true;
    }
}
