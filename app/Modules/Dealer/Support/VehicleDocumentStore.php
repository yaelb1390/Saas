<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Support;

use App\Modules\Dealer\Enums\DocumentType;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Models\VehicleDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Los papeles de una unidad.
 *
 * A diferencia de las fotos, aquí NO se toca el fichero: un PDF de matrícula o un contrato firmado se
 * guardan tal cual llegaron. Recomprimir un documento legal sería alterarlo.
 *
 * El nombre con el que se guarda es un identificador aleatorio y NO el que subió el usuario. Es
 * deliberado: un nombre de fichero controlado por quien sube es por donde se cuelan travesías de
 * directorio y nombres que el sistema operativo interpreta. El nombre original se guarda en la base
 * y se le devuelve al descargar.
 */
final class VehicleDocumentStore
{
    private const DIR = 'vehicle-docs';

    /** El mismo disco que las fotos: quien configura el almacenamiento lo hace una vez. */
    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.product_images', 'local'));
    }

    public function store(Vehicle $vehicle, UploadedFile $file, DocumentType $type, ?string $notes = null): VehicleDocument
    {
        // La extensión sale de lo que el fichero ES, no de cómo se llama. `extension()` la deduce del
        // contenido; `getClientOriginalExtension()` se cree lo que diga el navegador.
        $extension = $file->extension() ?: 'bin';
        $path = self::DIR.'/'.Str::ulid()->toBase32().'.'.$extension;

        // Con `throw: true`: sin él, un fallo de escritura dejaría una fila apuntando a un fichero
        // que no existe, y el dealer creería tener guardada una matrícula que no está.
        self::disk()->put($path, (string) file_get_contents((string) $file->getRealPath()), ['throw' => true]);

        return VehicleDocument::create([
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->id,
            'type' => $type,
            'path' => $path,
            // Se limpia: llega del navegador y acaba en una cabecera de descarga.
            'original_name' => Str::limit(basename((string) $file->getClientOriginalName()), 120, ''),
            'mime' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'notes' => $notes,
            'user_id' => auth()->id(),
        ]);
    }

    public function delete(VehicleDocument $document): void
    {
        $path = (string) $document->path;

        if ($path !== '' && self::disk()->exists($path)) {
            self::disk()->delete($path);
        }

        // La fila se borra DESPUÉS del fichero. Al revés, un fallo al borrar el fichero dejaría un
        // documento huérfano en el disco que nadie sabría que está ahí.
        $document->delete();
    }
}
