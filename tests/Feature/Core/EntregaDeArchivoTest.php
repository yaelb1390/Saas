<?php

declare(strict_types=1);

use App\Modules\Core\Support\EntregaDeArchivo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as RespuestaBase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * La entrega de ficheros privados.
 *
 * Lo que se vigila aquí no es que «funcione»: es que las dos ramas —firmar y servir— se comporten
 * igual de bien. En desarrollo SIEMPRE se toma la de servir, y la de firmar solo se ejerce en
 * producción; sin estos tests, un fallo al firmar no aparecería hasta que lo viera un cliente.
 */

/** Un disco que NO sabe firmar: el disco local de toda la vida. */
function discoSinFirma(): Filesystem
{
    Storage::fake('sinfirma');
    $disk = Storage::disk('sinfirma');
    $disk->put('fotos/carro.jpg', 'los-bytes-de-la-foto');

    return $disk;
}

/**
 * Un disco que SÍ sabe firmar.
 *
 * `buildTemporaryUrlsUsing` es la puerta que usa Laravel para esto, y es la misma que activa el
 * adaptador de S3. Así se ejerce la rama de producción sin tocar la red ni tener credenciales.
 *
 * @param  array<string, mixed>|null  $capturado  recoge con qué argumentos se pidió la firma
 */
function discoConFirma(?array &$capturado = null): Filesystem
{
    Storage::fake('confirma');
    $disk = Storage::disk('confirma');
    $disk->put('fotos/carro.jpg', 'los-bytes-de-la-foto');

    $disk->buildTemporaryUrlsUsing(function ($path, $expiration, $options = []) use (&$capturado) {
        $capturado = ['path' => $path, 'expiration' => $expiration, 'options' => $options];

        return 'https://almacen.ejemplo/'.$path.'?caduca='.$expiration->getTimestamp();
    });

    return $disk;
}

/** El cuerpo de una respuesta, sea normal o por trozos. */
function cuerpoDe(RespuestaBase $respuesta): string
{
    ob_start();
    $respuesta->sendContent();

    return (string) ob_get_clean();
}

function maxAgeDe(RespuestaBase $respuesta): int
{
    preg_match('/max-age=(\d+)/', (string) $respuesta->headers->get('Cache-Control'), $m);

    return (int) ($m[1] ?? 0);
}

it('sirve la imagen por PHP cuando el disco no sabe firmar', function () {
    $respuesta = EntregaDeArchivo::imagen(discoSinFirma(), 'fotos/carro.jpg');

    expect($respuesta->getStatusCode())->toBe(200)
        ->and($respuesta->getContent())->toBe('los-bytes-de-la-foto');
});

/*
 * EL CASO QUE SE ME ESCAPÓ Y POR EL QUE EXISTE `apuntaAOtroSitio`.
 *
 * El disco LOCAL también sabe firmar: Laravel trae `'serve' => true` y monta una ruta `/storage/...`
 * con firma. O sea que preguntar solo «¿sabe firmar?» contesta que sí en desarrollo, y allí firmar no
 * ahorra nada —la dirección firmada la sirve esta misma aplicación—: son los mismos bytes por PHP,
 * con un viaje de más y un 302 por delante. Se redirige solo si el destino EVITA nuestro servidor.
 */
it('no redirige si la direccion firmada vuelve a nosotros mismos', function () {
    Storage::fake('propio');
    $disk = Storage::disk('propio');
    $disk->put('fotos/carro.jpg', 'los-bytes-de-la-foto');

    $nuestroSitio = (string) config('app.url');
    $disk->buildTemporaryUrlsUsing(fn ($path) => $nuestroSitio.'/storage/'.$path.'?firma=abc');

    $respuesta = EntregaDeArchivo::imagen($disk, 'fotos/carro.jpg');

    expect($respuesta->getStatusCode())->toBe(200)
        ->and($respuesta->getContent())->toBe('los-bytes-de-la-foto');
});

it('redirige a una direccion firmada cuando el disco sabe firmar', function () {
    $respuesta = EntregaDeArchivo::imagen(discoConFirma(), 'fotos/carro.jpg');

    expect($respuesta->getStatusCode())->toBe(302)
        ->and($respuesta->headers->get('Location'))->toStartWith('https://almacen.ejemplo/fotos/carro.jpg')
        // Los bytes NO pasan por la función: eso es justamente lo que se quería conseguir.
        ->and($respuesta->getContent())->toBe('');
});

/*
 * EL TEST QUE MÁS IMPORTA DE LOS DE CACHÉ.
 *
 * Si el navegador se guardara la redirección más tiempo del que vive la firma, al reutilizarla iría a
 * una dirección caducada y la imagen saldría rota de forma intermitente. Se comprueba la RELACIÓN
 * entre los dos números, no los números: así el test sigue defendiendo la regla aunque mañana cambien
 * las cifras.
 */
it('no guarda la redireccion mas tiempo del que vive la firma', function () {
    $capturado = null;
    $respuesta = EntregaDeArchivo::imagen(discoConFirma($capturado), 'fotos/carro.jpg');

    $vidaDeLaFirma = $capturado['expiration']->getTimestamp() - now()->getTimestamp();

    expect(maxAgeDe($respuesta))->toBeGreaterThan(0)
        ->and(maxAgeDe($respuesta))->toBeLessThan($vidaDeLaFirma);
});

/*
 * Y la invariante tiene que aguantar aunque quien llame pida una barbaridad: el logo pide un año
 * porque su dirección lleva marca de tiempo, pero eso vale para el FICHERO, no para la redirección.
 */
it('recorta la cache de la redireccion aunque le pidan un anio', function () {
    $capturado = null;
    $respuesta = EntregaDeArchivo::imagen(
        discoConFirma($capturado), 'fotos/carro.jpg', 'image/jpeg', EntregaDeArchivo::CACHE_ANIO,
    );

    $vidaDeLaFirma = $capturado['expiration']->getTimestamp() - now()->getTimestamp();

    expect(maxAgeDe($respuesta))->toBeLessThan($vidaDeLaFirma);
});

it('marca la cache como privada, que son ficheros de una sola empresa', function () {
    $servido = EntregaDeArchivo::imagen(discoSinFirma(), 'fotos/carro.jpg');
    $firmado = EntregaDeArchivo::imagen(discoConFirma(), 'fotos/carro.jpg');

    expect($servido->headers->get('Cache-Control'))->toContain('private')
        ->and($servido->headers->get('Cache-Control'))->not->toContain('public')
        ->and($firmado->headers->get('Cache-Control'))->toContain('private');
});

/*
 * Si firmar revienta —credenciales de S3 a medias, por ejemplo— se cae al camino de siempre. Una
 * imagen que no se puede firmar no puede tumbar la pantalla entera.
 */
it('sirve el fichero igualmente si firmar falla', function () {
    Storage::fake('roto');
    $disk = Storage::disk('roto');
    $disk->put('fotos/carro.jpg', 'los-bytes-de-la-foto');
    $disk->buildTemporaryUrlsUsing(function () {
        throw new RuntimeException('las credenciales no valen');
    });

    $respuesta = EntregaDeArchivo::imagen($disk, 'fotos/carro.jpg');

    expect($respuesta->getStatusCode())->toBe(200)
        ->and($respuesta->getContent())->toBe('los-bytes-de-la-foto');
});

it('se puede apagar la firma desde la configuracion', function () {
    config(['filesystems.firmar_entregas' => false]);

    expect(EntregaDeArchivo::imagen(discoConFirma(), 'fotos/carro.jpg')->getStatusCode())->toBe(200);
});

it('responde 404 si el fichero no esta, firme o no firme', function () {
    expect(fn () => EntregaDeArchivo::imagen(discoSinFirma(), 'fotos/no-existe.jpg'))
        ->toThrow(NotFoundHttpException::class);

    expect(fn () => EntregaDeArchivo::imagen(discoConFirma(), 'fotos/no-existe.jpg'))
        ->toThrow(NotFoundHttpException::class);
});

it('sirve el documento por trozos y con su nombre cuando no se puede firmar', function () {
    $respuesta = EntregaDeArchivo::documento(discoSinFirma(), 'fotos/carro.jpg', 'matricula.pdf', 'application/pdf');

    expect($respuesta->getStatusCode())->toBe(200)
        ->and(cuerpoDe($respuesta))->toBe('los-bytes-de-la-foto')
        ->and($respuesta->headers->get('Content-Disposition'))->toContain('matricula.pdf');
});

it('le pide al almacenamiento que devuelva el documento con su nombre', function () {
    $capturado = null;
    EntregaDeArchivo::documento(discoConFirma($capturado), 'fotos/carro.jpg', 'matricula.pdf', 'application/pdf');

    expect($capturado['options']['ResponseContentDisposition'] ?? '')->toBe('inline; filename="matricula.pdf"')
        ->and($capturado['options']['ResponseContentType'] ?? '')->toBe('application/pdf');
});

/*
 * Un documento lleva la cédula del comprador y su dirección firmada funciona SIN sesión: es una llave
 * suelta. Ni se guarda en el navegador ni se le da la vida larga de una imagen.
 */
it('el documento no se guarda en cache y su firma dura menos que la de una imagen', function () {
    $deDocumento = null;
    $deImagen = null;

    $respuesta = EntregaDeArchivo::documento(discoConFirma($deDocumento), 'fotos/carro.jpg', 'x.pdf');
    EntregaDeArchivo::imagen(discoConFirma($deImagen), 'fotos/carro.jpg');

    expect($respuesta->headers->get('Cache-Control'))->toContain('no-store')
        ->and($deDocumento['expiration']->getTimestamp())
        ->toBeLessThan($deImagen['expiration']->getTimestamp());
});

/*
 * El nombre lo pone quien sube el fichero, así que no es de fiar. Con un salto de línea dentro se
 * parte la cabecera en dos y se cuela otra inyectada; `addslashes()`, que es lo que se usaba en las
 * rutas que guardan el fichero en la base, escapa la comilla pero NO se lleva el salto.
 */
it('no deja que el nombre del fichero parta la cabecera', function () {
    $sucio = "factura\r\nX-Colada: si\".pdf";
    $limpio = EntregaDeArchivo::nombreSeguro($sucio);

    $capturado = null;
    EntregaDeArchivo::documento(discoConFirma($capturado), 'fotos/carro.jpg', $sucio);
    $servido = EntregaDeArchivo::documento(discoSinFirma(), 'fotos/carro.jpg', $sucio);

    expect($limpio)->not->toContain("\r")
        ->and($limpio)->not->toContain("\n")
        ->and($limpio)->not->toContain('"')
        ->and($capturado['options']['ResponseContentDisposition'])->not->toContain("\n")
        ->and((string) $servido->headers->get('Content-Disposition'))->not->toContain("\n");
});

it('un nombre que se queda en nada no deja la cabecera vacia', function () {
    expect(EntregaDeArchivo::nombreSeguro("\r\n\"\""))->toBe('archivo');
});
