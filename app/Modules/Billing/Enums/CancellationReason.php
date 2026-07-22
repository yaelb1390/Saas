<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * Códigos de anulación de comprobantes fiscales de la DGII (RD).
 *
 * Es el valor que viaja en la tercera columna del formato 608 (Comprobantes Anulados).
 * El código debe reportarse tal cual: no es texto libre.
 */
enum CancellationReason: string
{
    case DeterioroFactura = '01';
    case ErroresImpresion = '02';
    case ImpresionDefectuosa = '03';
    case DuplicidadFactura = '04';
    case CorreccionInformacion = '05';
    case CambioProductos = '06';
    case DevolucionProductos = '07';
    case OmisionProductos = '08';
    case ErroresSecuenciaNcf = '09';
    case CeseOperaciones = '10';
    case PerdidaTalonario = '11';

    public function label(): string
    {
        return match ($this) {
            self::DeterioroFactura => 'Deterioro de factura pre-impresa',
            self::ErroresImpresion => 'Errores de impresión',
            self::ImpresionDefectuosa => 'Impresión defectuosa',
            self::DuplicidadFactura => 'Duplicidad de factura',
            self::CorreccionInformacion => 'Corrección de la información',
            self::CambioProductos => 'Cambio de productos',
            self::DevolucionProductos => 'Devolución de productos',
            self::OmisionProductos => 'Omisión de productos',
            self::ErroresSecuenciaNcf => 'Errores en secuencia de NCF',
            self::CeseOperaciones => 'Cese de operaciones',
            self::PerdidaTalonario => 'Pérdida o hurto de talonarios',
        };
    }
}
