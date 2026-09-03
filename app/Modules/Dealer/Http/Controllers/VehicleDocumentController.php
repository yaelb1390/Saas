<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Support\EntregaDeArchivo;
use App\Modules\Dealer\Enums\DocumentType;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Models\VehicleDocument;
use App\Modules\Dealer\Support\VehicleDocumentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Los papeles de una unidad.
 *
 * MÁS CERRADO QUE LAS FOTOS, y a propósito: aquí dentro va la matrícula con los datos del titular, la
 * factura con el precio real y el contrato con la cédula del comprador. Ver la galería de un carro es
 * inofensivo; ver sus papeles, no. Por eso las rutas exigen `vehicles.manage` y no `vehicles.view`.
 *
 * Igual que las fotos, el aislamiento viene del enlace de ruta —el vehículo de otra empresa no se
 * resuelve— más la comprobación de que el documento es de ESE vehículo.
 */
final class VehicleDocumentController extends Controller
{
    private const TOPE = 30;

    public function index(Vehicle $vehicle): JsonResponse
    {
        if (! DbTable::existe('vehicle_documents')) {
            return response()->json(['documentos' => []]);
        }

        return response()->json([
            'documentos' => $vehicle->documents()->get()->map(fn (VehicleDocument $d): array => [
                'id' => $d->id,
                'tipo' => $d->type->label(),
                'tono' => $d->type->badgeClass(),
                'nombre' => $d->original_name,
                'tamano' => $d->tamanoLegible(),
                'fecha' => $d->created_at?->format('d/m/Y'),
                'notas' => $d->notes,
                'url' => route('panel.vehicles.documents.show', [$vehicle->id, $d->id]),
            ])->all(),
        ]);
    }

    public function store(Request $request, Vehicle $vehicle, VehicleDocumentStore $documentos): RedirectResponse
    {
        abort_unless(DbTable::existe('vehicle_documents'), 404);

        $datos = $request->validate([
            /*
             * Se limita QUÉ se puede subir, no solo el tamaño.
             *
             * Es la puerta por la que entra un fichero al servidor. Una lista de tipos permitidos
             * —papeles y fotos de papeles— deja fuera lo que no tiene ninguna razón de estar aquí.
             */
            'document' => ['required', 'file', 'mimes:pdf,jpeg,jpg,png,webp', 'max:15360'],
            'type' => ['required', 'string', 'in:'.implode(',', array_column(DocumentType::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'document.required' => 'Elige el archivo.',
            'document.mimes' => 'Solo se admiten PDF o imágenes.',
            'document.max' => 'El archivo pesa más de 15 MB.',
            'type.required' => 'Di qué documento es.',
        ]);

        if ($vehicle->documents()->count() >= self::TOPE) {
            return back()->with('panel_error', 'Esta unidad ya tiene el máximo de '.self::TOPE.' documentos.');
        }

        $documentos->store(
            $vehicle,
            $request->file('document'),
            DocumentType::from($datos['type']),
            $datos['notes'] ?? null,
        );

        return back()->with('panel_success', 'Documento guardado.');
    }

    /**
     * Descarga el documento con el nombre que le puso el usuario.
     *
     * En el disco se llama con un identificador aleatorio —el nombre de fichero que sube alguien no
     * se usa nunca para escribir— pero al bajarlo hay que devolverle «matricula.pdf» y no
     * «01JB3K….pdf», que no le dice nada a nadie.
     */
    public function show(Vehicle $vehicle, VehicleDocument $document): Response
    {
        $doc = $this->suyo($vehicle, $document);

        // La entrega decide sola si firma o sirve, y en las dos ramas mantiene lo importante de aquí:
        // sin caché —son papeles con datos personales que pasan por intermediarios— y con el nombre
        // que le puso el usuario, no el identificador aleatorio con el que se guardó en el disco.
        return EntregaDeArchivo::documento(
            VehicleDocumentStore::disk(),
            (string) $doc->path,
            (string) $doc->original_name,
            $doc->mime ?: 'application/octet-stream',
        );
    }

    public function destroy(Vehicle $vehicle, VehicleDocument $document, VehicleDocumentStore $documentos): RedirectResponse
    {
        $documentos->delete($this->suyo($vehicle, $document));

        return back()->with('panel_success', 'Documento eliminado.');
    }

    /** El documento, comprobando que es de ESE vehículo. Ver la nota de la clase. */
    private function suyo(Vehicle $vehicle, VehicleDocument $document): VehicleDocument
    {
        abort_unless((int) $document->vehicle_id === (int) $vehicle->id, 404);

        return $document;
    }
}
