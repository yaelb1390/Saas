<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * Tipo de Bienes y Servicios Comprados según la DGII (columna del formato 606). El valor es el
 * código de dos dígitos que espera el envío.
 */
enum GoodsServicesType: string
{
    case Personal = '01';
    case TrabajosSuministrosServicios = '02';
    case Arrendamientos = '03';
    case ActivosFijos = '04';
    case Representacion = '05';
    case OtrasDeducciones = '06';
    case Financieros = '07';
    case Extraordinarios = '08';
    case CostoDeVenta = '09';
    case Adquisiciones = '10';
    case Seguros = '11';

    public function label(): string
    {
        return match ($this) {
            self::Personal => '01 · Gastos de personal',
            self::TrabajosSuministrosServicios => '02 · Trabajos, suministros y servicios',
            self::Arrendamientos => '03 · Arrendamientos',
            self::ActivosFijos => '04 · Gastos de activos fijos',
            self::Representacion => '05 · Gastos de representación',
            self::OtrasDeducciones => '06 · Otras deducciones admitidas',
            self::Financieros => '07 · Gastos financieros',
            self::Extraordinarios => '08 · Gastos extraordinarios',
            self::CostoDeVenta => '09 · Compras/gastos del costo de venta',
            self::Adquisiciones => '10 · Adquisiciones de activos',
            self::Seguros => '11 · Gastos de seguros',
        };
    }
}
