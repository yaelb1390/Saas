<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use DomainException;

/**
 * Errores de negocio del inventario. Los controladores los capturan y los convierten en un mensaje
 * `panel_error` para el usuario, sin abortar con un 500.
 */
final class StockException extends DomainException
{
    public static function remesaVacia(): self
    {
        return new self('No hay nada que entrar: escanea o busca al menos un producto.');
    }

    public static function invalidQuantity(): self
    {
        return new self('La cantidad tiene que ser mayor que cero.');
    }

    public static function warehouseNotInCompany(): self
    {
        return new self('El almacén seleccionado no pertenece a esta empresa.');
    }

    public static function productNotInCompany(): self
    {
        return new self('Uno de los productos de la lista no pertenece a esta empresa.');
    }
}
