<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Core\Tenancy\CurrentCompany;

/**
 * Cuánto puede rebajar quien está cobrando.
 *
 * POR QUÉ EXISTE. Hasta ahora no había ni permiso ni límite: cualquiera con acceso al cobro podía
 * aplicar el descuento que quisiera —incluido el 100 %— y el servidor lo aceptaba. Un descuento es
 * dinero que sale del negocio, y en un mostrador lo aplica quien atiende, no quien decide precios.
 *
 * LA REGLA, en una frase: quien tiene `sales.discount` rebaja libre; quien no, hasta el porcentaje
 * que fije la empresa. Ese porcentaje vive en los ajustes de la empresa y no en el código porque una
 * ferretería y una cafetería no tienen el mismo margen.
 *
 * SE COMPRUEBA EN EL SERVIDOR y no solo en pantalla. La pantalla avisa para que no se pierda el
 * tiempo, pero quien manda es esto: un `curl` con el descuento a mano tiene que encontrarse el mismo
 * muro que el navegador. Es la misma doctrina que el precio, que siempre se relee del catálogo.
 */
final class TopeDeDescuento
{
    /** Dónde vive el tope dentro de `companies.settings`. */
    public const AJUSTE = 'discount_max_percent';

    /**
     * El porcentaje máximo para quien NO tiene el permiso.
     *
     * Diez por ciento de fábrica: cubre la rebaja de mostrador de toda la vida —quitar los pesos
     * sueltos para redondear— sin dejar que se regale un artículo. Quien quiera otro número lo
     * cambia en los ajustes de su empresa.
     */
    public function porcentaje(): string
    {
        $empresa = app(CurrentCompany::class)->model();
        $configurado = data_get($empresa?->settings, self::AJUSTE);

        $valor = $configurado !== null && $configurado !== ''
            ? (string) $configurado
            : (string) config('bmos.descuentos.tope_por_ciento', '10');

        // Un tope negativo o disparatado se ignora: dejaría el mostrador sin poder rebajar un peso,
        // o abriría la mano del todo, y en los dos casos sin que nadie lo hubiera querido.
        return bccomp($valor, '0', 2) < 0 || bccomp($valor, '100', 2) > 0 ? '10' : $valor;
    }

    /** ¿Este usuario puede rebajar sin límite? */
    public function sinLimite(?User $usuario): bool
    {
        return $usuario?->can('sales.discount') ?? false;
    }

    /**
     * El descuento máximo EN DINERO que admite un importe bruto para este usuario.
     *
     * Devuelve null cuando no hay límite, que no es lo mismo que un tope enorme: quien llama tiene
     * que poder distinguir «rebaja libre» de «rebaja mucho» al explicárselo a quien cobra.
     */
    public function maximoEnDinero(?User $usuario, string $bruto): ?string
    {
        if ($this->sinLimite($usuario)) {
            return null;
        }

        return bcdiv(bcmul($bruto, $this->porcentaje(), 4), '100', 2);
    }

    /**
     * ¿Se pasa de la raya este descuento?
     *
     * Se compara contra el BRUTO de la venta —lo que costaría sin rebajas— y no contra el neto: si
     * se midiera sobre lo ya rebajado, el porcentaje se calcularía sobre un número que el propio
     * descuento hace más pequeño, y el tope daría de sí cuanto más se rebaja.
     */
    public function excedido(?User $usuario, string $bruto, string $descuento): bool
    {
        $maximo = $this->maximoEnDinero($usuario, $bruto);

        if ($maximo === null) {
            return false;
        }

        return bccomp($descuento, $maximo, 2) > 0;
    }
}
