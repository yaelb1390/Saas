<?php

/*
 * Configuración fiscal (RD).
 *
 * prices_include_tax = true  → el precio del producto es lo que paga el cliente (ITBIS incluido).
 *                              La factura desglosa hacia atrás: base = precio / 1.18.
 * itbis_rate               → tasa general del ITBIS en porcentaje.
 *
 * Hoy la tasa es única para todos los productos. Si en el futuro hacen falta tasas por producto
 * (16 % reducida, 0 % exentos), solo cambia TaxCalculator: nadie más calcula impuestos.
 */
return [
    'itbis_rate' => env('BILLING_ITBIS_RATE', '18'),
    'prices_include_tax' => (bool) env('BILLING_PRICES_INCLUDE_TAX', true),
];
