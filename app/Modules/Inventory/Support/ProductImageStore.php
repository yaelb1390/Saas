<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Product;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda/borra la foto de un producto en el disco local. Redimensiona con GD a un tamaño razonable
 * (para que el POS cargue rápido y ocupe poco). A prueba de fallos: si GD no está disponible o no
 * puede leer la imagen, guarda el archivo original tal cual.
 */
final class ProductImageStore
{
    private const DIR = 'products';

    private const MAX_SIDE = 800;

    /**
     * Proporción del lienzo: 3 de ancho por 4 de alto (vertical).
     *
     * Vertical y no cuadrado porque los productos que se venden en mostrador son casi siempre más
     * altos que anchos —una botella, un vaso de batida, un cono— y en un lienzo cuadrado quedaban
     * con franjas a los lados. Con este formato, una foto vertical llena la ficha entera.
     */
    private const RATIO_W = 3;

    private const RATIO_H = 4;

    /**
     * Disco donde viven las fotos: del servidor en local, Supabase Storage en producción.
     *
     * Lo deciden las variables de entorno (ver config/filesystems.php). Todo el que toque fotos
     * —subida, borrado, servicio y recuadrado— pasa por aquí, para que no puedan divergir.
     */
    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.product_images', 'local'));
    }

    public function store(Product $product, UploadedFile $file): void
    {
        $bytes = $this->resize((string) file_get_contents((string) $file->getRealPath()));
        $path = self::DIR.'/'.Str::ulid()->toBase32().'.jpg';

        // Sin `throw: true` un fallo de escritura pasaría inadvertido y el producto quedaría
        // apuntando a una foto que no existe. Es justo lo que ocurría en producción: el disco era
        // de solo lectura y la subida moría con un 500 sin explicar por qué.
        self::disk()->put($path, $bytes, ['throw' => true]);

        $this->deleteFile($product->image_path);
        $product->update(['image_path' => $path]);
    }

    public function delete(Product $product): void
    {
        $this->deleteFile($product->image_path);

        if ($product->image_path !== null) {
            $product->update(['image_path' => null]);
        }
    }

    private function deleteFile(?string $path): void
    {
        if ($path !== null && $path !== '' && self::disk()->exists($path)) {
            self::disk()->delete($path);
        }
    }

    /**
     * Normaliza la foto a un lienzo VERTICAL 3:4 de fondo blanco y devuelve JPEG.
     *
     * Se recuadra al guardar y no al mostrar porque las fotos llegan con proporciones dispares y en
     * la rejilla del punto de venta eso obligaba a elegir entre dos males: recortar el producto
     * (`cover`) o dejar franjas de fondo (`contain`). Normalizando en la subida, todas las fichas
     * quedan iguales y ninguna foto pierde nada. Es además el sitio barato: se hace una vez por
     * imagen, no en cada visita.
     *
     * Nunca amplía: una foto pequeña se centra en un lienzo de su tamaño en vez de estirarse y
     * salir pixelada.
     *
     * Devuelve el original si GD no está disponible o no puede leer la imagen.
     */
    private function resize(string $bytes): string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return $bytes;
        }

        $src = @imagecreatefromstring($bytes);

        if ($src === false) {
            return $bytes;
        }

        $w = imagesx($src);
        $h = imagesy($src);

        // Alto del lienzo: el mínimo que permite que la foto quepa entera en proporción 3:4, con
        // tope. Se parte del mayor entre el alto real y el que exigiría el ancho, así una foto ya
        // vertical apenas gana margen y una apaisada lo gana arriba y abajo.
        $alto = (int) min(self::MAX_SIDE, max($h, (int) ceil($w * self::RATIO_H / self::RATIO_W)));
        $ancho = max(1, (int) round($alto * self::RATIO_W / self::RATIO_H));

        // La imagen se escala para caber dentro del lienzo conservando su proporción. Nunca amplía.
        $scale = min(1.0, $ancho / $w, $alto / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($ancho, $alto);

        // Fondo blanco: aplana transparencias (PNG/WEBP) al pasar a JPEG y da el mismo lienzo a
        // todas las fichas.
        imagefilledrectangle($dst, 0, 0, $ancho, $alto, imagecolorallocate($dst, 255, 255, 255));

        // Centrada, para que el producto quede en el medio de la ficha.
        imagecopyresampled(
            $dst, $src,
            (int) (($ancho - $nw) / 2), (int) (($alto - $nh) / 2),
            0, 0,
            $nw, $nh, $w, $h,
        );

        ob_start();
        imagejpeg($dst, null, 82);
        $out = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $out !== '' ? $out : $bytes;
    }
}
