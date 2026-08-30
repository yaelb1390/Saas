<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\DbTable;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Models\VehiclePhoto;
use App\Modules\Dealer\Support\VehicleImageStore;
use App\Modules\Dealer\Support\VehiclePhotoStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * La galería de una unidad.
 *
 * TODAS las acciones reciben el vehículo por enlace de ruta, y por tanto pasan por el ámbito de
 * empresa: la unidad de otro negocio ni se resuelve. La foto se busca DENTRO de ese vehículo, no por
 * su id suelto, así que un identificador de otra galería tampoco encuentra nada.
 *
 * Es importante que el aislamiento venga de ahí y no de una comprobación escrita a mano: esto sirve
 * FICHEROS, y una comprobación olvidada filtraría las fotos de un negocio al de al lado sin dejar el
 * menor rastro —nadie revisa los registros buscando descargas de imágenes—.
 */
final class VehiclePhotoController extends Controller
{
    /** Cuántas fotos como mucho por unidad. Con más, la ficha deja de ser útil y el disco engorda. */
    private const TOPE = 20;

    public function index(Vehicle $vehicle): JsonResponse
    {
        if (! DbTable::existe('vehicle_photos')) {
            return response()->json(['fotos' => []]);
        }

        return response()->json([
            'fotos' => $vehicle->photos()->get()->map(fn (VehiclePhoto $f): array => [
                'id' => $f->id,
                'url' => $f->url(),
                'principal' => (bool) $f->is_primary,
            ])->all(),
        ]);
    }

    public function store(Request $request, Vehicle $vehicle, VehiclePhotoStore $fotos): RedirectResponse
    {
        abort_unless(DbTable::existe('vehicle_photos'), 404);

        $request->validate([
            // Varias de una vez: el dealer fotografía el carro entero y sube el lote.
            'photos' => ['required', 'array', 'max:'.self::TOPE],
            // 8 MB por foto: se limita ANTES de tocarla porque el recuadrado con GD carga la imagen
            // entera en memoria, y una foto de móvil moderno son diez megas.
            'photos.*' => ['image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ], [
            'photos.required' => 'Elige al menos una foto.',
            'photos.*.image' => 'Uno de los archivos no es una imagen.',
            'photos.*.max' => 'Alguna foto pesa más de 8 MB.',
        ]);

        $yaTiene = $vehicle->photos()->count();
        $entran = array_slice($request->file('photos'), 0, max(0, self::TOPE - $yaTiene));

        if ($entran === []) {
            return back()->with('panel_error', 'Esta unidad ya tiene el máximo de '.self::TOPE.' fotos.');
        }

        foreach ($entran as $archivo) {
            $fotos->add($vehicle, $archivo);
        }

        $puestas = count($entran);
        $mensaje = $puestas === 1 ? 'Foto añadida.' : "{$puestas} fotos añadidas.";

        // Se dice cuántas se quedaron fuera en vez de descartarlas en silencio.
        if ($puestas < count($request->file('photos'))) {
            $mensaje .= ' Las demás no entraron: el máximo son '.self::TOPE.'.';
        }

        return back()->with('panel_success', $mensaje);
    }

    /** Marca cuál es la principal: la que se ve en la lista del patio. */
    public function principal(Vehicle $vehicle, VehiclePhoto $photo, VehiclePhotoStore $fotos): RedirectResponse
    {
        $fotos->fijarPrincipal($vehicle, $this->suya($vehicle, $photo));

        return back()->with('panel_success', 'Esa es ahora la foto principal.');
    }

    public function destroy(Vehicle $vehicle, VehiclePhoto $photo, VehiclePhotoStore $fotos): RedirectResponse
    {
        $fotos->delete($vehicle, $this->suya($vehicle, $photo));

        return back()->with('panel_success', 'Foto eliminada.');
    }

    /** Guarda el orden que el dealer dejó arrastrando. */
    public function reordenar(Request $request, Vehicle $vehicle, VehiclePhotoStore $fotos): JsonResponse
    {
        $datos = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $fotos->reordenar($vehicle, array_map('intval', $datos['ids']));

        return response()->json(['ok' => true]);
    }

    public function show(Vehicle $vehicle, VehiclePhoto $photo): Response
    {
        $foto = $this->suya($vehicle, $photo);

        $disk = VehicleImageStore::disk();
        $path = (string) $foto->path;

        abort_unless($disk->exists($path), 404);

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'image/jpeg',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    /**
     * La foto, comprobando que es de ESE vehículo.
     *
     * El enlace de ruta ya garantiza que el vehículo es de la empresa; esto cierra la otra mitad: que
     * el id de la foto no venga de la galería de otra unidad. Sin ello, `/vehiculos/1/fotos/999`
     * serviría la foto 999 aunque fuese de otro carro —y de otra empresa—.
     */
    private function suya(Vehicle $vehicle, VehiclePhoto $photo): VehiclePhoto
    {
        abort_unless((int) $photo->vehicle_id === (int) $vehicle->id, 404);

        return $photo;
    }
}
