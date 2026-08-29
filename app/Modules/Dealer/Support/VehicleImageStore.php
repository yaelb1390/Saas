<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Support;

use App\Modules\Core\Support\ImagenRecuadrada;
use App\Modules\Dealer\Models\Vehicle;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La foto de una unidad del patio.
 *
 * Mismo planteamiento que la foto de producto —y el mismo recuadrado, compartido en
 * `ImagenRecuadrada`— pero con el lienzo al revés: **4:3 horizontal**. Un carro es ancho, y en el
 * lienzo vertical de los productos habría salido diminuto entre dos franjas blancas enormes.
 */
final class VehicleImageStore
{
    private const DIR = 'vehicles';

    /** Tope del lado largo. Da de sobra para la ficha y no engorda el disco de nadie. */
    private const MAX_SIDE = 1000;

    private const RATIO_W = 4;

    private const RATIO_H = 3;

    /**
     * Disco donde viven las fotos: el mismo que usan las de producto.
     *
     * Comparte ajuste a propósito: quien configura el almacenamiento de su empresa lo hace una vez,
     * no una por módulo. En local es el disco del servidor; en producción, Supabase Storage.
     */
    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.product_images', 'local'));
    }

    public function store(Vehicle $vehicle, UploadedFile $file): void
    {
        $bytes = ImagenRecuadrada::recuadrar(
            (string) file_get_contents((string) $file->getRealPath()),
            self::RATIO_W,
            self::RATIO_H,
            self::MAX_SIDE,
        );

        $path = self::DIR.'/'.Str::ulid()->toBase32().'.jpg';

        // Con `throw: true`: sin él, un fallo de escritura pasaría inadvertido y la unidad quedaría
        // apuntando a una foto que no existe. Es lo que ya ocurrió con las fotos de producto en
        // producción, donde el disco era de solo lectura y la subida moría sin explicar por qué.
        self::disk()->put($path, $bytes, ['throw' => true]);

        $this->deleteFile($vehicle->photo_path);
        $vehicle->update(['photo_path' => $path]);
    }

    public function delete(Vehicle $vehicle): void
    {
        $this->deleteFile($vehicle->photo_path);

        if ($vehicle->photo_path !== null) {
            $vehicle->update(['photo_path' => null]);
        }
    }

    private function deleteFile(?string $path): void
    {
        if ($path !== null && $path !== '' && self::disk()->exists($path)) {
            self::disk()->delete($path);
        }
    }
}
