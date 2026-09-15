<?php

declare(strict_types=1);

namespace App\Modules\Rental\Support;

use App\Modules\Core\Support\ImagenRecuadrada;
use App\Modules\Rental\Models\VehicleInspection;
use App\Modules\Rental\Models\VehicleInspectionPhoto;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Las fotos de una inspección de entrega/devolución.
 *
 * Mismo recuadrado 4:3 horizontal que las fotos del vehículo (`VehicleImageStore` en el Dealer) y el
 * mismo disco compartido —`product_images`, configurado una vez por toda la empresa—, pero sin
 * duplicar esa clase: aquí no hay concepto de «principal» que gestionar, solo una galería simple.
 */
final class VehicleInspectionPhotoStore
{
    private const DIR = 'vehicle-inspections';

    private const MAX_SIDE = 1000;

    private const RATIO_W = 4;

    private const RATIO_H = 3;

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.product_images', 'local'));
    }

    public function add(VehicleInspection $inspection, UploadedFile $file): VehicleInspectionPhoto
    {
        $bytes = ImagenRecuadrada::recuadrar(
            (string) file_get_contents((string) $file->getRealPath()),
            self::RATIO_W,
            self::RATIO_H,
            self::MAX_SIDE,
        );

        $path = self::DIR.'/'.Str::ulid()->toBase32().'.jpg';
        self::disk()->put($path, $bytes, ['throw' => true]);

        return VehicleInspectionPhoto::create([
            'company_id' => $inspection->company_id,
            'vehicle_inspection_id' => $inspection->id,
            'path' => $path,
            'position' => (int) $inspection->photos()->max('position') + 1,
        ]);
    }

    /** La firma capturada en el canvas, como un PNG en base64 (data URI). */
    public function storeSignature(VehicleInspection $inspection, string $dataUri): string
    {
        $bytes = base64_decode((string) preg_replace('#^data:image/\w+;base64,#', '', $dataUri), true) ?: '';

        $path = self::DIR.'/firmas/'.Str::ulid()->toBase32().'.png';
        self::disk()->put($path, $bytes, ['throw' => true]);

        return $path;
    }

    public function delete(VehicleInspectionPhoto $foto): void
    {
        if (self::disk()->exists($foto->path)) {
            self::disk()->delete($foto->path);
        }

        $foto->delete();
    }
}
