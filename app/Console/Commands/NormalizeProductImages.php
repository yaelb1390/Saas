<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\ProductImageStore;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Vuelve a procesar las fotos ya guardadas para dejarlas cuadradas y con fondo blanco.
 *
 * `ProductImageStore` normaliza desde ahora todo lo que se sube, pero las fotos anteriores se
 * guardaron con su proporción original y en la rejilla del punto de venta se ven con franjas a los
 * lados. Este comando las pone al día sin tener que volver a subirlas una por una.
 */
final class NormalizeProductImages extends Command
{
    protected $signature = 'productos:normalizar-fotos {--dry-run : Solo informa, no toca nada}';

    protected $description = 'Recuadra las fotos de producto ya guardadas (cuadrado con fondo blanco)';

    public function handle(ProductImageStore $images): int
    {
        $seco = (bool) $this->option('dry-run');

        // Sin el scope de empresa: es una tarea de mantenimiento de toda la plataforma.
        $productos = Product::withoutCompanyScope()->withTrashed()
            ->whereNotNull('image_path')->get();

        if ($productos->isEmpty()) {
            $this->info('No hay fotos que normalizar.');

            return self::SUCCESS;
        }

        $hechas = 0;
        $saltadas = 0;

        foreach ($productos as $producto) {
            $ruta = (string) $producto->image_path;

            if (! Storage::disk('local')->exists($ruta)) {
                $this->warn("Falta el archivo de «{$producto->name}»: {$ruta}");
                $saltadas++;

                continue;
            }

            [$ancho, $alto] = @getimagesize(Storage::disk('local')->path($ruta)) ?: [0, 0];

            // Ya está en 3:4: no se vuelve a comprimir. Reprocesar un JPEG que ya pasó por aquí solo
            // le quitaría calidad.
            if ($alto > 0 && $ancho === (int) round($alto * 3 / 4)) {
                $saltadas++;

                continue;
            }

            if ($seco) {
                $this->line("Se recuadraría: {$producto->name} ({$ancho}×{$alto})");
                $hechas++;

                continue;
            }

            try {
                // Se reutiliza el mismo camino que una subida real para que el resultado sea
                // idéntico: una sola implementación del recuadrado, no dos que puedan divergir.
                $temporal = tempnam(sys_get_temp_dir(), 'img');
                file_put_contents($temporal, Storage::disk('local')->get($ruta));

                $images->store($producto, new UploadedFile($temporal, basename($ruta), null, null, true));

                @unlink($temporal);
                $hechas++;
            } catch (Throwable $e) {
                $this->error("Falló «{$producto->name}»: ".$e->getMessage());
                $saltadas++;
            }
        }

        $verbo = $seco ? 'se recuadrarían' : 'recuadradas';
        $this->info("Fotos {$verbo}: {$hechas}. Sin cambios: {$saltadas}.");

        return self::SUCCESS;
    }
}
