<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

/**
 * La CATEGORÍA de un gasto: la lista cerrada, igual para todos los negocios.
 *
 * ================================================================================================
 * OJO AL NOMBRE, QUE ES LA TRAMPA DE ESTE MÓDULO.
 *
 *   · Esto (`ExpenseGroup`)     es lo que la pantalla llama «CATEGORÍA». Fija, la misma para todos.
 *   · El modelo `ExpenseCategory` es lo que la pantalla llama «CONCEPTO».  La inventa cada negocio.
 *
 * El desajuste viene de antes: el modelo se llamó `ExpenseCategory` cuando solo había un nivel.
 * Renombrarlo hoy tocaría la tabla, las rutas, los permisos y media docena de pantallas, así que se
 * queda como está y se documenta aquí, que es donde el que venga detrás va a tropezar.
 * ================================================================================================
 *
 * POR QUÉ DOS NIVELES. «Café en grano» y «Leche» son cosas distintas que comprar, pero la misma cosa
 * que presupuestar: comida. Con un solo nivel hay que elegir entre presupuestar de más —«Alimentos» y
 * perder el detalle de qué se compra— o de menos —presupuestar el café por su lado, que nadie hace—.
 *
 * Y LA CATEGORÍA CUELGA DEL CONCEPTO, no del gasto. Un concepto pertenece siempre a la misma
 * categoría, así que guardarla en cada gasto seria repetir el mismo dato miles de veces y abrir la
 * puerta a que dos gastos del mismo concepto acaben en categorías distintas.
 */
enum ExpenseGroup: string
{
    case Alimentos = 'alimentos';
    case Bebidas = 'bebidas';
    case Insumos = 'insumos';
    case Limpieza = 'limpieza';
    case Servicios = 'servicios';
    case Alquiler = 'alquiler';
    case Nomina = 'nomina';
    case Transporte = 'transporte';
    case Publicidad = 'publicidad';
    case Mantenimiento = 'mantenimiento';
    case Equipos = 'equipos';
    case Impuestos = 'impuestos';
    case Otros = 'otros';

    public function label(): string
    {
        return match ($this) {
            self::Alimentos => 'Alimentos',
            self::Bebidas => 'Bebidas',
            self::Insumos => 'Insumos',
            self::Limpieza => 'Limpieza',
            self::Servicios => 'Servicios',
            self::Alquiler => 'Alquiler',
            self::Nomina => 'Nómina',
            self::Transporte => 'Transporte',
            self::Publicidad => 'Publicidad',
            self::Mantenimiento => 'Mantenimiento',
            self::Equipos => 'Equipos',
            self::Impuestos => 'Impuestos',
            self::Otros => 'Otros',
        };
    }

    /**
     * El color con el que se pinta en la tabla y en los desgloses.
     *
     * Se decide AQUÍ y no en la vista: el mismo gasto sale en la dinámica, en el detalle y en el
     * análisis, y con el color en cada plantilla acabarían siendo tres tonos distintos para
     * «Alimentos» según dónde se mire.
     */
    public function tono(): string
    {
        return match ($this) {
            self::Alimentos, self::Bebidas, self::Insumos => 'amber',
            self::Limpieza, self::Mantenimiento, self::Equipos => 'blue',
            self::Servicios, self::Alquiler => 'violet',
            self::Nomina, self::Impuestos => 'rose',
            self::Transporte, self::Publicidad => 'emerald',
            self::Otros => 'slate',
        };
    }

    /**
     * Lo que se ofrece al clasificar un concepto.
     *
     * @return array<int, self>
     */
    public static function opciones(): array
    {
        return self::cases();
    }

    /**
     * La categoría de un concepto sin clasificar.
     *
     * Null y «Otros» NO son lo mismo: null es «nadie lo ha mirado todavía» y sale así en la pantalla,
     * para que se note y se clasifique. «Otros» es una decisión: no encaja en ninguna. Confundirlos
     * haría que los conceptos viejos parecieran clasificados sin que nadie los hubiera visto.
     */
    public static function deValor(?string $valor): ?self
    {
        return $valor === null ? null : self::tryFrom($valor);
    }
}
