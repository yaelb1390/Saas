<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Normaliza una foto a un lienzo de proporción fija, con fondo blanco, y la devuelve en JPEG.
 *
 * Estaba dentro de `ProductImageStore`, encerrada en un método privado y con la proporción escrita
 * a fuego: vertical 3:4. Eso es lo que le conviene a una botella o a un vaso de batida, y lo peor
 * posible para un carro, que es ancho. Cuando el patio de vehículos necesitó lo mismo en horizontal,
 * copiarlo habría dejado DOS recuadrados que hay que corregir a la vez.
 *
 * Aquí no se sabe de productos ni de vehículos: entran bytes y una proporción, y salen bytes.
 *
 * Se recuadra al GUARDAR y no al mostrar porque las fotos llegan con proporciones dispares, y en una
 * rejilla eso obliga a elegir entre recortar la foto o dejar franjas de fondo. Normalizando en la
 * subida, todas las fichas quedan iguales, ninguna foto pierde nada, y se hace una vez por imagen en
 * vez de en cada visita.
 */
final class ImagenRecuadrada
{
    /** Calidad del JPEG. Por debajo de 80 se nota en las fotos con degradados; por encima pesa. */
    private const CALIDAD = 82;

    /**
     * Devuelve la imagen recuadrada, o la original si no se puede procesar.
     *
     * NUNCA AMPLÍA: una foto pequeña se centra en un lienzo de su tamaño en vez de estirarse y salir
     * pixelada. Es preferible una miniatura pequeña y nítida a una grande y sucia.
     *
     * A prueba de fallos a propósito: si GD no está o no sabe leer el fichero, se devuelve lo que
     * entró. Una foto sin recuadrar es un defecto estético; perder la foto del cliente, no.
     *
     * @param  int  $anchoRelativo  Proporción del lienzo, parte ancha (3 en 3:4, 4 en 4:3).
     * @param  int  $altoRelativo  Proporción del lienzo, parte alta.
     * @param  int  $ladoMaximo  Tope del lado largo, en píxeles.
     */
    public static function recuadrar(
        string $bytes,
        int $anchoRelativo,
        int $altoRelativo,
        int $ladoMaximo,
    ): string {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return $bytes;
        }

        $src = @imagecreatefromstring($bytes);

        if ($src === false) {
            return $bytes;
        }

        $w = imagesx($src);
        $h = imagesy($src);

        /*
         * El lienzo: el mínimo que permite que la foto quepa entera en la proporción pedida. Se
         * parte del mayor entre el lado real y el que exigiría el otro lado, así una foto que ya
         * viene en esa proporción apenas gana margen, y una que viene al revés lo gana a los lados
         * o arriba y abajo.
         */
        $alto = max($h, (int) ceil($w * $altoRelativo / $anchoRelativo));
        $ancho = max(1, (int) round($alto * $anchoRelativo / $altoRelativo));

        /*
         * EL TOPE SE APLICA AL LADO LARGO, sea cual sea la orientación.
         *
         * Antes se le aplicaba al alto, y funcionaba de casualidad: el único usuario era el lienzo
         * vertical 3:4 de los productos, donde el alto ES el lado largo. Al estrenar el 4:3
         * horizontal de los vehículos, topar el alto dejaba pasar anchos de 1200 px con el tope
         * puesto en 1000. Se ve en el número, no en el ojo.
         */
        $largo = max($ancho, $alto);

        if ($largo > $ladoMaximo) {
            $factor = $ladoMaximo / $largo;
            $ancho = max(1, (int) round($ancho * $factor));
            $alto = max(1, (int) round($alto * $factor));
        }

        // La imagen se escala para caber dentro del lienzo conservando su proporción. Nunca amplía.
        $escala = min(1.0, $ancho / $w, $alto / $h);
        $nw = max(1, (int) round($w * $escala));
        $nh = max(1, (int) round($h * $escala));

        $dst = imagecreatetruecolor($ancho, $alto);

        // Fondo blanco: aplana transparencias (PNG/WEBP) al pasar a JPEG y da el mismo lienzo a
        // todas las fichas.
        imagefilledrectangle($dst, 0, 0, $ancho, $alto, imagecolorallocate($dst, 255, 255, 255));

        // Centrada, para que el objeto quede en el medio.
        imagecopyresampled(
            $dst, $src,
            (int) (($ancho - $nw) / 2), (int) (($alto - $nh) / 2),
            0, 0,
            $nw, $nh, $w, $h,
        );

        ob_start();
        imagejpeg($dst, null, self::CALIDAD);
        $out = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $out !== '' ? $out : $bytes;
    }
}
