<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Enums;

/**
 * En qué se gastó el dinero de una unidad.
 *
 * TODOS entran en el costo real. No hay tipos «que no cuentan»: si se pagó por ese carro, forma parte
 * de lo que costó, y separarlos sirve para saber en qué se va el dinero —no para dejar alguno fuera
 * de la suma—.
 */
enum ExpenseType: string
{
    case Reparacion = 'reparacion';
    case Importacion = 'importacion';
    case Transporte = 'transporte';
    case Documentacion = 'documentacion';
    case Matriculacion = 'matriculacion';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Reparacion => 'Reparación',
            self::Importacion => 'Importación',
            self::Transporte => 'Transporte',
            self::Documentacion => 'Documentación',
            self::Matriculacion => 'Matriculación',
            self::Otro => 'Otro',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Reparacion => 'badge-amber',
            self::Importacion => 'badge-violet',
            self::Transporte => 'badge-blue',
            self::Documentacion => 'badge-gray',
            self::Matriculacion => 'badge-gray',
            self::Otro => 'badge-gray',
        };
    }

    /**
     * Si lo hace el taller.
     *
     * La pantalla de Taller enseña solo estos: anotar ahí un arancel de aduana no ayuda a nadie a
     * saber qué carros están en el elevador.
     */
    public function esDeTaller(): bool
    {
        return $this === self::Reparacion;
    }
}
