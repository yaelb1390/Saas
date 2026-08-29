<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Core\Support\ImagenRecuadrada;
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
     * Recuadra a lienzo VERTICAL 3:4.
     *
     * El recuadrado en sí vive en `ImagenRecuadrada`, compartido con la foto del patio de
     * vehículos, que usa 4:3 horizontal. Aquí solo se dice la proporción: un producto de mostrador
     * —una botella, un vaso, un cono— es más alto que ancho, y en un lienzo cuadrado quedaba con
     * franjas a los lados.
     */
    private function resize(string $bytes): string
    {
        return ImagenRecuadrada::recuadrar($bytes, self::RATIO_W, self::RATIO_H, self::MAX_SIDE);
    }
}
