<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Support;

use App\Modules\Core\Support\ImagenRecuadrada;
use App\Modules\Delivery\Models\Delivery;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La foto que prueba que el pedido llegó.
 *
 * POR QUÉ EXISTE. Cuando un cliente llama diciendo «a mí no me entregaron nada», hoy la única
 * respuesta del sistema es la palabra del repartidor contra la del cliente. Una foto del paquete en
 * la puerta cierra esa discusión en diez segundos y sin que nadie quede como mentiroso — y eso
 * protege sobre todo al repartidor, que es quien no tiene con qué defenderse.
 *
 * NO TIENE NADA QUE VER CON EL PAGO. Él no cobra: esto solo confirma que la mercancía llegó.
 *
 * SE REDUCE ANTES DE SUBIRLA, y no es un detalle: la foto sale de la cámara del móvil, que hoy
 * dispara cuatro o cinco megas, y se sube con los datos del repartidor desde la calle. Recuadrada a
 * 4:3 con el lado largo a 1200 px se queda en unos cientos de kilobytes, que es la diferencia entre
 * que suba en un semáforo o que se quede colgada.
 *
 * NUNCA EN DISCO PÚBLICO. Es la puerta de la casa de un cliente: se sirve siempre con URL firmada,
 * igual que las imágenes de producto. Ver `EntregaDeArchivo`.
 */
final class EvidenciaDeEntrega
{
    private const DIR = 'entregas';

    /** 4:3 y no cuadrado: en una foto de una puerta, recortar a cuadrado se come justo el contexto. */
    private const RATIO_W = 4;

    private const RATIO_H = 3;

    private const MAX_SIDE = 1200;

    /**
     * El disco donde vive.
     *
     * Cae en el mismo que las imágenes de producto, que en producción ya apunta a R2. Así esto
     * funciona el día que se despliega sin tener que añadir otra variable de entorno — y quien quiera
     * separarlas puede hacerlo con `DELIVERY_EVIDENCE_DISK` sin tocar código.
     */
    public static function disk(): Filesystem
    {
        return Storage::disk((string) config(
            'filesystems.delivery_evidence',
            (string) config('filesystems.product_images', 'local'),
        ));
    }

    public function guardar(Delivery $delivery, UploadedFile $file): void
    {
        $bytes = ImagenRecuadrada::recuadrar(
            (string) file_get_contents((string) $file->getRealPath()),
            self::RATIO_W,
            self::RATIO_H,
            self::MAX_SIDE,
        );

        $ruta = self::DIR.'/'.Str::ulid()->toBase32().'.jpg';

        /*
         * Con `throw: true`. Sin él, un fallo de escritura pasaría inadvertido y la entrega quedaría
         * apuntando a una foto que no existe: justo el caso en que alguien va a mirarla es cuando hay
         * una reclamación, y encontrarse un hueco entonces es peor que no haber prometido nada.
         */
        self::disk()->put($ruta, $bytes, ['throw' => true]);

        // La anterior se borra: un reintento del día siguiente no debe dejar huérfana la de ayer.
        $this->borrarFichero($delivery->evidence_path);

        $delivery->update(['evidence_path' => $ruta, 'evidence_at' => now()]);
    }

    private function borrarFichero(?string $ruta): void
    {
        if ($ruta !== null && $ruta !== '' && self::disk()->exists($ruta)) {
            self::disk()->delete($ruta);
        }
    }
}
