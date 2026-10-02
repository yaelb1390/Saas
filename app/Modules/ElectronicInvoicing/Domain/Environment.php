<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Domain;

/**
 * Ambiente de la DGII en el que trabaja una empresa [DT pp.6–7].
 *
 * Es parte de la secuencia, del documento, de la ruta donde se guarda y de cada llamada: un
 * documento de pruebas nunca debe salir hacia producción ni al revés. Solo producción tiene validez
 * fiscal.
 */
enum Environment: string
{
    case Pruebas = 'pruebas';
    case Certificacion = 'certificacion';
    case Produccion = 'produccion';

    public function label(): string
    {
        return (string) config("ecf.environments.{$this->value}.label", $this->value);
    }

    /** Segmento de la URL que selecciona el ambiente en los servicios de la DGII. */
    public function segment(): string
    {
        return (string) config("ecf.environments.{$this->value}.segment");
    }

    public function isFiscal(): bool
    {
        return (bool) config("ecf.environments.{$this->value}.fiscal", false);
    }
}
