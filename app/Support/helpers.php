<?php

declare(strict_types=1);

use App\Modules\Core\Tenancy\CurrentCompany;

if (! function_exists('money')) {
    /**
     * Formatea un importe como moneda para mostrar en la interfaz.
     *
     * El sistema opera en pesos dominicanos (RD$). El símbolo se antepone y el número se agrupa por
     * miles con dos decimales. La fuente del importe puede ser string (decimales de la BD), int o
     * float; se normaliza a float para el formateo.
     *
     * Se resuelve el símbolo desde la moneda de la empresa activa cuando está disponible, con RD$
     * como valor por defecto: todos los negocios de la plataforma facturan en DOP.
     */
    function money(string|int|float|null $amount, bool $withSymbol = true): string
    {
        $formatted = number_format((float) ($amount ?? 0), 2, '.', ',');

        if (! $withSymbol) {
            return $formatted;
        }

        return currency_symbol().' '.$formatted;
    }
}

if (! function_exists('currency_symbol')) {
    /**
     * Símbolo de la moneda de la empresa activa. RD$ para DOP (por defecto de la plataforma).
     */
    function currency_symbol(): string
    {
        $currency = 'DOP';

        if (app()->bound(CurrentCompany::class)) {
            $company = app(CurrentCompany::class)->model();
            $currency = $company->currency ?? 'DOP';
        }

        return match ($currency) {
            'DOP' => 'RD$',
            'USD' => 'US$',
            'EUR' => '€',
            default => $currency.' ',
        };
    }
}
