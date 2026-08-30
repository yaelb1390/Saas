<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Support;

use App\Modules\Core\Support\ImagenRecuadrada;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Models\VehiclePhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La galería de una unidad: subir, ordenar, marcar la principal y borrar.
 *
 * ES EL ÚNICO SITIO QUE TOCA `vehicles.photo_path`. Esa columna repite cuál es la principal para que
 * la lista del patio no tenga que resolverlo con una consulta por fila —serían cientos—, y un dato
 * repetido solo es seguro si hay un único lugar que lo escribe. Si esto se hiciera también desde el
 * controlador, el día que alguien se olvide, la miniatura enseñaría una foto que ya no es la
 * principal.
 */
final class VehiclePhotoStore
{
    private const DIR = 'vehicles';

    private const MAX_SIDE = 1000;

    /** Horizontal, como los carros. Las fotos de producto van al revés, 3:4. */
    private const RATIO_W = 4;

    private const RATIO_H = 3;

    public function add(Vehicle $vehicle, UploadedFile $file): VehiclePhoto
    {
        return DB::transaction(function () use ($vehicle, $file): VehiclePhoto {
            $bytes = ImagenRecuadrada::recuadrar(
                (string) file_get_contents((string) $file->getRealPath()),
                self::RATIO_W,
                self::RATIO_H,
                self::MAX_SIDE,
            );

            $path = self::DIR.'/'.Str::ulid()->toBase32().'.jpg';
            VehicleImageStore::disk()->put($path, $bytes, ['throw' => true]);

            // La primera que se sube es la principal. Si no, el dealer sube tres fotos y la lista
            // sigue enseñando el recuadro gris hasta que se acuerde de marcar una.
            $esLaPrimera = $vehicle->photos()->count() === 0;

            $foto = VehiclePhoto::create([
                'company_id' => $vehicle->company_id,
                'vehicle_id' => $vehicle->id,
                'path' => $path,
                'position' => (int) $vehicle->photos()->max('position') + 1,
                'is_primary' => $esLaPrimera,
                'user_id' => auth()->id(),
            ]);

            if ($esLaPrimera) {
                $this->fijarPrincipal($vehicle, $foto);
            }

            return $foto;
        });
    }

    /** Marca una foto como principal y desmarca la anterior. */
    public function fijarPrincipal(Vehicle $vehicle, VehiclePhoto $foto): void
    {
        DB::transaction(function () use ($vehicle, $foto): void {
            $vehicle->photos()->where('id', '!=', $foto->id)->update(['is_primary' => false]);
            $foto->forceFill(['is_primary' => true])->save();

            // La copia en `vehicles`, que es la que pinta la lista.
            $vehicle->forceFill(['photo_path' => $foto->path])->save();
        });
    }

    /**
     * Borra una foto.
     *
     * Si era la principal, ASCIENDE la siguiente. Dejar la unidad sin principal teniendo fotos haría
     * que la lista enseñara el recuadro gris de «sin foto» con la galería llena, y nadie entendería
     * por qué.
     */
    public function delete(Vehicle $vehicle, VehiclePhoto $foto): void
    {
        DB::transaction(function () use ($vehicle, $foto): void {
            $eraLaPrincipal = (bool) $foto->is_primary;
            $ruta = (string) $foto->path;

            $foto->delete();

            if (VehicleImageStore::disk()->exists($ruta)) {
                VehicleImageStore::disk()->delete($ruta);
            }

            if (! $eraLaPrincipal) {
                return;
            }

            $siguiente = $vehicle->photos()->orderBy('position')->first();

            if ($siguiente !== null) {
                $this->fijarPrincipal($vehicle, $siguiente);

                return;
            }

            // No quedan fotos: la unidad vuelve a no tener miniatura.
            $vehicle->forceFill(['photo_path' => null])->save();
        });
    }

    /**
     * Reordena la galería.
     *
     * @param  array<int, int>  $idsEnOrden
     */
    public function reordenar(Vehicle $vehicle, array $idsEnOrden): void
    {
        DB::transaction(function () use ($vehicle, $idsEnOrden): void {
            // Se recorren las fotos DE LA UNIDAD y se busca su sitio en la lista recibida, en vez de
            // recorrer la lista recibida. Así un id colado que no sea de este vehículo sencillamente
            // no encuentra nada que mover.
            $posiciones = array_flip(array_values($idsEnOrden));

            foreach ($vehicle->photos()->get() as $foto) {
                $nueva = $posiciones[$foto->id] ?? null;

                if ($nueva !== null && (int) $foto->position !== (int) $nueva) {
                    $foto->forceFill(['position' => (int) $nueva])->save();
                }
            }
        });
    }
}
