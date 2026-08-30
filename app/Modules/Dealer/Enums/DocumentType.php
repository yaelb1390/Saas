<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Enums;

/** Qué papel es. Los que de verdad pide un dealer dominicano, no una lista genérica. */
enum DocumentType: string
{
    case Matricula = 'matricula';
    case Factura = 'factura';
    case Seguro = 'seguro';
    case Importacion = 'importacion';
    case Contrato = 'contrato';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Matricula => 'Matrícula',
            self::Factura => 'Factura',
            self::Seguro => 'Seguro',
            self::Importacion => 'Documentos de importación',
            self::Contrato => 'Contrato',
            self::Otro => 'Otro',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Matricula => 'badge-blue',
            self::Factura => 'badge-green',
            self::Seguro => 'badge-violet',
            self::Importacion => 'badge-amber',
            self::Contrato => 'badge-gray',
            self::Otro => 'badge-gray',
        };
    }
}
