<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as RespuestaBase;
use Throwable;

/**
 * Entrega un fichero privado sin hacerlo pasar por PHP cuando el almacenamiento sabe firmar.
 *
 * EL PROBLEMA. Cinco rutas sirven ficheros guardados en un disco —logo de la empresa, foto de
 * producto, foto de vehículo, galería del vehículo y sus documentos— y todas hacían lo mismo: leer el
 * fichero entero y devolverlo por la respuesta. En un servidor propio eso da igual. En producción,
 * donde el disco es Supabase Storage y la aplicación corre en funciones de Vercel, cada miniatura
 * hace viajar los bytes DOS veces —del almacenamiento a la función y de la función al navegador— y
 * se pagan las dos. Una galería de doscientos vehículos lo nota.
 *
 * LA SOLUCIÓN. Si el disco sabe generar direcciones firmadas y temporales, esto no devuelve el
 * fichero: devuelve un 302 a una dirección que caduca. El navegador baja los bytes directamente del
 * almacenamiento y por la función solo pasan las cabeceras.
 *
 * LO QUE NO CAMBIA. El permiso se sigue comprobando antes: la firma se emite solo después de que el
 * controlador haya resuelto el modelo con su ámbito de empresa. Y si el disco no sabe firmar —el
 * disco local, y también `Storage::fake` en los tests— se sirve el fichero como toda la vida. Por eso
 * esto entra en producción sin cambiar en nada el entorno de desarrollo.
 *
 * LO QUE NO ARREGLA. Los documentos del CRM y las facturas de compra no pasan por aquí porque no
 * viven en un disco: se guardan en base64 DENTRO de PostgreSQL. Eso ocupa un tercio más que el
 * fichero original y se lo come del cupo de la base. Sacarlos a un disco es harina de otro costal
 * —hace falta migración y traslado de lo ya guardado— y está anotado como pendiente.
 */
final class EntregaDeArchivo
{
    /** Cuánto vive la firma de una imagen. De sobra para seguir el 302, corto para que no se comparta. */
    private const FIRMA_IMAGEN = 30;

    /**
     * Cuánto vive la firma de un documento.
     *
     * Más corta que la de las imágenes a propósito: un papel de estos lleva la cédula del comprador o
     * el precio pactado, y la dirección firmada es una llave que funciona sin sesión. Cinco minutos
     * bastan para que el navegador la siga y no para que sirva de algo pegada en un chat.
     */
    private const FIRMA_DOCUMENTO = 5;

    /**
     * Tope de lo que el navegador puede guardarse la REDIRECCIÓN.
     *
     * Esto es una INVARIANTE, no un ajuste: tiene que ser menor que la vida de la firma. Si el
     * navegador se guardara el 302 más tiempo del que dura la firma, al reutilizarlo iría a una
     * dirección ya caducada y la imagen saldría rota de forma intermitente, que es lo peor de
     * depurar. Por eso se aplica como TOPE con `min()` sobre lo que pida quien llama, en vez de
     * confiar en que nadie pase un número mayor.
     */
    private const TOPE_CACHE_REDIRECCION = 600;

    /** Lo que se guarda una imagen servida por PHP: una semana. */
    public const CACHE_SEMANA = 604800;

    /** Un año, para direcciones que llevan marca de tiempo y cambian solas al cambiar el fichero. */
    public const CACHE_ANIO = 31536000;

    /**
     * Una imagen que se ve dentro de la página.
     *
     * `$cache` es cuánto puede guardarla el navegador; quien pone la marca de tiempo en la dirección
     * puede pedir un año sin riesgo, porque al cambiar el fichero cambia la URL.
     */
    public static function imagen(
        Filesystem $disk,
        string $path,
        string $tipoPorDefecto = 'image/jpeg',
        int $cache = self::CACHE_SEMANA,
    ): Response {
        abort_unless($disk->exists($path), 404);

        $firmada = self::firmar($disk, $path, self::FIRMA_IMAGEN, []);

        if ($firmada !== null) {
            return self::redireccion($firmada, 'private, max-age='.min($cache, self::TOPE_CACHE_REDIRECCION));
        }

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: $tipoPorDefecto,
            /*
             * `private`, no `public`. Son ficheros de UNA empresa detrás de sesión; con `public` un
             * intermediario compartido tendría permiso para guardarlos y servírselos a otro. Las
             * rutas de foto de producto y de vehículo ponían `public`, y era un descuido.
             */
            'Cache-Control' => 'private, max-age='.$cache,
        ]);
    }

    /**
     * Un documento, que se abre con su nombre original.
     *
     * `inline` y no `attachment` a propósito: una matrícula se quiere MIRAR, y forzar la descarga
     * obliga a abrir el gestor de archivos para leer una línea.
     */
    public static function documento(
        Filesystem $disk,
        string $path,
        string $nombre,
        string $mime = 'application/octet-stream',
    ): RespuestaBase {
        abort_unless($disk->exists($path), 404);

        $disposicion = self::disposicion($nombre);

        $firmada = self::firmar($disk, $path, self::FIRMA_DOCUMENTO, [
            'ResponseContentDisposition' => $disposicion,
            'ResponseContentType' => $mime,
        ]);

        if ($firmada !== null) {
            // Sin guardar: son papeles con datos personales y pueden pasar por intermediarios.
            return self::redireccion($firmada, 'private, max-age=0, no-store');
        }

        /*
         * `response()` del disco y no `get()`: esto devuelve el fichero por trozos en vez de cargarlo
         * entero en memoria. Con una foto da igual, pero un PDF escaneado de veinte megas cargado de
         * golpe se lleva por delante el límite de memoria de la función.
         */
        return $disk->response($path, $nombre, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposicion,
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    /**
     * Deja el nombre de un fichero en algo que se puede meter en una cabecera.
     *
     * Público porque lo necesitan también las dos rutas que guardan el fichero en la base de datos y
     * no pasan por aquí. Usaban `addslashes()`, que escapa la comilla pero NO se lleva el salto de
     * línea, que es justo con lo que se parte una cabecera en dos y se cuela otra inyectada. El
     * nombre lo pone quien sube el fichero, así que no es de fiar.
     */
    public static function nombreSeguro(string $nombre): string
    {
        // `[^\P{C}]` es «un carácter de control»: la doble negación es la forma de decirlo en una
        // clase de caracteres. Con ellos se van el \r y el \n; con `"` y `\`, el escape de la comilla.
        $limpio = preg_replace('/[^\P{C}]|["\\\\]/u', '', $nombre) ?? '';

        return mb_substr(trim($limpio), 0, 120) ?: 'archivo';
    }

    /**
     * Un 302 a mano en vez de `redirect()->away()`.
     *
     * La diferencia importa: los controladores de imagen declaran devolver `Illuminate\Http\Response`
     * y `RedirectResponse` no lo es. Construyéndolo así, la firma de todos ellos se queda como estaba
     * y este cambio no arrastra siete modificaciones de tipo que no aportan nada.
     */
    private static function redireccion(string $destino, string $cache): Response
    {
        return response('', 302, ['Location' => $destino, 'Cache-Control' => $cache]);
    }

    private static function disposicion(string $nombre): string
    {
        return 'inline; filename="'.self::nombreSeguro($nombre).'"';
    }

    /**
     * La dirección firmada, o null si aquí no se puede o no merece la pena firmar.
     *
     * El `catch` no es decorativo: firmar habla con la configuración del disco y, si las credenciales
     * de S3 están a medias, revienta. Que una imagen no se pueda firmar no puede tumbar la pantalla
     * entera; devolviendo null se cae al camino de siempre, que funciona.
     *
     * @param  array<string, string>  $opciones
     */
    private static function firmar(Filesystem $disk, string $path, int $minutos, array $opciones): ?string
    {
        if (! config('filesystems.firmar_entregas', true)) {
            return null;
        }

        if (! $disk instanceof FilesystemAdapter || ! $disk->providesTemporaryUrls()) {
            return null;
        }

        try {
            $url = $disk->temporaryUrl($path, self::caducidad($minutos), $opciones);
        } catch (Throwable) {
            return null;
        }

        return self::apuntaAOtroSitio($url) ? $url : null;
    }

    /**
     * ¿La dirección firmada lleva a otro servidor, o vuelve a nosotros?
     *
     * ESTA COMPROBACIÓN ES EL CORAZÓN DE LA CLASE, y no sobra. El disco local TAMBIÉN sabe firmar
     * —Laravel trae `'serve' => true` y monta una ruta `/storage/...` con firma— así que preguntar
     * solo si sabe firmar diría que sí en desarrollo. Y firmar ahí no ahorra nada: la dirección
     * firmada la sirve esta misma aplicación, así que los bytes vuelven a pasar por PHP, con un viaje
     * de más y un 302 por delante.
     *
     * Lo que se quiere saber no es si el disco sabe firmar, sino si firmar EVITA nuestro servidor. Por
     * eso se mira el destino: si es otro sitio, se redirige; si somos nosotros, se sirve y ya está.
     */
    private static function apuntaAOtroSitio(string $url): bool
    {
        $destino = parse_url($url, PHP_URL_HOST);

        // Sin anfitrión es una dirección relativa, o sea nuestra.
        if (! is_string($destino) || $destino === '') {
            return false;
        }

        $nuestro = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $destino !== $nuestro;
    }

    private static function caducidad(int $minutos): DateTimeInterface
    {
        return now()->addMinutes($minutos);
    }
}
