<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Product;
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

    public function store(Product $product, UploadedFile $file): void
    {
        $bytes = $this->resize((string) file_get_contents((string) $file->getRealPath()));
        $path = self::DIR.'/'.Str::ulid()->toBase32().'.jpg';

        Storage::disk('local')->put($path, $bytes);

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
        if ($path !== null && $path !== '' && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * Redimensiona a máx. {MAX_SIDE}px por el lado mayor y devuelve JPEG. Devuelve el original si GD
     * no está o no puede procesar la imagen.
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
        $scale = min(1.0, self::MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        // Fondo blanco: aplana transparencias (PNG/WEBP) al pasar a JPEG.
        imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, 82);
        $out = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $out !== '' ? $out : $bytes;
    }
}
