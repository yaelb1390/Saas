<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\Company;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda, sirve y borra el logo de una empresa.
 *
 * Mismo planteamiento que las fotos de producto: la decisión del disco vive en la configuración y
 * TODO el que toca el logo pregunta aquí, para que no puedan divergir quien lo sube, quien lo enseña
 * y quien lo mete en el PDF.
 *
 * No se redimensiona en el servidor: en producción (vercel-php) no hay GD ni Imagick, así que
 * intentarlo sería escribir código que solo se ejecuta en local. El recorte se hace en el navegador
 * antes de subir —igual que con las fotos de producto—, y aquí solo se comprueba el tamaño.
 */
final class CompanyLogoStore
{
    private const DIR = 'logos';

    /**
     * Tope de lo que se acepta guardar, ya recortado por el navegador.
     *
     * Un logo es un adorno de cabecera: 300 KB dan de sobra para verlo nítido en un recibo. Aceptar
     * el original de 5 MB que sale de un móvil haría que cada impresión se descargara eso, y en el
     * PDF térmico se incrusta en base64, así que el archivo entero viaja dentro del documento.
     */
    public const MAX_BYTES = 300 * 1024;

    /**
     * Alto máximo del logo dentro de un PDF de 80 mm, en puntos.
     *
     * Vive aquí y no suelto en la plantilla porque quien calcula el ALTO DEL PAPEL necesita el mismo
     * número: el rollo se corta a una medida que se calcula sumando cabecera, líneas y pie, y si el
     * logo ocupa un espacio que ese cálculo no conoce, el recibo se parte en dos páginas. Pasó al
     * añadir el logo: el ticket de cinco artículos empezó a salir en dos hojas.
     *
     * 64 y no 48: con 48 los logos cuadrados —la mayoría de los emblemas de negocio, redondos o de
     * escudo— salían muy pequeños, porque un logo cuadrado nunca llega a usar el ancho disponible y
     * es el ALTO el único que decide su tamaño. Subirlo es lo que los agranda de verdad.
     *
     * No más: en el rollo térmico una imagen grande tarda en salir y, con mucho color, la impresora
     * la deja en un borrón gris. 64pt son unos 22 mm de los 80 del papel.
     */
    public const PDF_ALTO_PT = 64;

    /** Lo que hay que sumarle al alto del papel cuando la empresa tiene logo (imagen + su margen). */
    public const PDF_ESPACIO_PT = self::PDF_ALTO_PT + 10;

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.company_logos', 'local'));
    }

    public function store(Company $company, UploadedFile $file): void
    {
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        $extension = mb_strtolower($file->getClientOriginalExtension()) === 'png' ? 'png' : 'jpg';

        $path = self::DIR.'/'.Str::ulid()->toBase32().'.'.$extension;

        // `throw: true`: sin esto, un fallo de escritura pasaría inadvertido y la empresa quedaría
        // apuntando a un logo que no existe. Es lo que ya pasó con las fotos de producto en
        // producción, donde el disco era de solo lectura y la subida moría con un 500 mudo.
        self::disk()->put($path, $bytes, ['throw' => true]);

        $this->deleteFile($company->logo_path);

        $company->forceFill(['logo_path' => $path])->save();
    }

    public function delete(Company $company): void
    {
        $this->deleteFile($company->logo_path);

        if ($company->logo_path !== null) {
            $company->forceFill(['logo_path' => null])->save();
        }
    }

    /**
     * El logo como `data:` URI, para incrustarlo en un PDF.
     *
     * dompdf no puede pedir una URL protegida por sesión —no lleva las cookies del usuario—, así que
     * la única forma de que el logo salga en el recibo impreso es viajar dentro del propio documento.
     *
     * Devuelve null si no hay logo o si el fichero no se puede leer: un recibo sin logo es un recibo;
     * un recibo que revienta al imprimirlo deja al cajero sin poder entregar nada.
     */
    public static function dataUri(?Company $company): ?string
    {
        $path = $company?->logo_path;

        if ($path === null || $path === '') {
            return null;
        }

        try {
            if (! self::disk()->exists($path)) {
                return null;
            }

            $bytes = self::disk()->get($path);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $mime = str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function deleteFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            if (self::disk()->exists($path)) {
                self::disk()->delete($path);
            }
        } catch (\Throwable $e) {
            // Que no se pueda borrar el fichero viejo no debe impedir guardar el nuevo: lo peor que
            // deja es un archivo huérfano, y el logo que ve el cliente sí queda correcto.
            report($e);
        }
    }
}
