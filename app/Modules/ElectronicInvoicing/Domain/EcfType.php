<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Domain;

/**
 * Tipos de comprobante fiscal electrónico [IT §6.1]. El valor es el código de dos dígitos que va
 * en el e-NCF tras la serie «E».
 *
 * Las etiquetas y el esquema de cada tipo viven en config/ecf.php, no aquí: si la DGII cambia un
 * nombre o publica un esquema nuevo, se toca la configuración y no el código.
 */
enum EcfType: int
{
    case CreditoFiscal = 31;
    case Consumo = 32;
    case NotaDebito = 33;
    case NotaCredito = 34;
    case Compras = 41;
    case GastosMenores = 43;
    case RegimenesEspeciales = 44;
    case Gubernamental = 45;
    case Exportacion = 46;
    case PagosExterior = 47;

    public function label(): string
    {
        return (string) config("ecf.types.{$this->value}.label", "e-CF {$this->value}");
    }

    /** Nombre del archivo XSD oficial de este tipo dentro de la carpeta de la especificación. */
    public function schemaFile(): string
    {
        return (string) config("ecf.types.{$this->value}.xsd");
    }

    /** Prefijo del e-NCF: serie + tipo, p. ej. «E31» [IT §7]. */
    public function prefix(): string
    {
        return config('ecf.encf.series', 'E').$this->value;
    }
}
