<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\SelectionType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Option;
use App\Modules\Inventory\Models\OptionGroup;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Catálogo de DEMOSTRACIÓN para probar la pantalla de venta rápida: categorías, productos con
 * existencia y una foto generada para cada uno.
 *
 * Las fotos se dibujan aquí con GD en vez de descargarse: el entorno de desarrollo no debe depender
 * de una red ni de un servicio externo para poder verse.
 *
 * Todos los SKU llevan el prefijo `DEMO-`, así que se distinguen del catálogo real de un vistazo y
 * se pueden borrar de golpe. Es idempotente: relanzarlo no duplica nada.
 */
final class DemoCatalogSeeder extends Seeder
{
    private const PREFIX = 'DEMO-';

    /** Icono de cada categoría en la barra lateral del punto de venta. */
    private const ICONS = [
        'Helados' => '🍦',
        'Paletas' => '🍧',
        'Batidas' => '🧋',
        'Hamburguesas' => '🍔',
        'Pizzas' => '🍕',
        'Bebidas' => '🥤',
        'Postres' => '🍰',
        'Combos' => '🎁',
    ];

    /** Paletas por categoría, para que cada grupo se reconozca por color en la rejilla. */
    private const PALETTES = [
        'Helados' => [[236, 72, 153], [244, 114, 182]],
        'Paletas' => [[59, 130, 246], [96, 165, 250]],
        'Batidas' => [[139, 92, 246], [167, 139, 250]],
        'Hamburguesas' => [[217, 119, 6], [245, 158, 11]],
        'Pizzas' => [[220, 38, 38], [248, 113, 113]],
        'Bebidas' => [[13, 148, 136], [45, 212, 191]],
        'Postres' => [[124, 58, 237], [196, 181, 253]],
        'Combos' => [[5, 150, 105], [52, 211, 153]],
    ];

    /** @var array<string, array<int, array{0: string, 1: int}>> nombre => [precio, ...] */
    private const CATALOG = [
        'Helados' => [
            ['Cono sencillo', 80], ['Cono doble', 120], ['Copa de vainilla', 150],
            ['Copa de chocolate', 150], ['Sundae de fresa', 180], ['Banana split', 260],
            ['Helado de pistacho', 190],
        ],
        'Paletas' => [
            ['Paleta de coco', 60], ['Paleta de limón', 60], ['Paleta de mango', 70],
            ['Paleta de chocolate', 80], ['Paleta de fresa', 70], ['Paleta de tamarindo', 75],
        ],
        'Batidas' => [
            ['Batida de lechosa', 140], ['Batida de guineo', 140], ['Batida de fresa', 160],
            ['Batida de morir soñando', 150], ['Batida de zapote', 170],
        ],
        'Hamburguesas' => [
            ['Hamburguesa clásica', 260], ['Hamburguesa con queso', 300],
            ['Hamburguesa doble', 420], ['Hamburguesa de pollo', 280], ['Hamburguesa BBQ', 340],
            ['Hamburguesa vegetariana', 290],
        ],
        'Pizzas' => [
            ['Pizza personal', 320], ['Pizza pepperoni', 480], ['Pizza hawaiana', 500],
            ['Pizza cuatro quesos', 540], ['Pizza vegetariana', 460], ['Pizza familiar', 850],
        ],
        'Bebidas' => [
            ['Refresco pequeño', 60], ['Refresco grande', 90], ['Agua embotellada', 45],
            ['Jugo natural', 110], ['Café', 70], ['Té frío', 85], ['Malta', 80],
        ],
        'Postres' => [
            ['Flan', 120], ['Tres leches', 180], ['Brownie', 140],
            ['Cheesecake', 200], ['Arroz con leche', 100],
        ],
        'Combos' => [
            ['Combo burger + papas', 380], ['Combo pizza + refresco', 520],
            ['Combo infantil', 290], ['Combo familiar', 1200], ['Combo helado + café', 210],
        ],
    ];

    public function run(): void
    {
        $companyId = app(CurrentCompany::class)->id();

        if ($companyId === null) {
            $this->command?->error('No hay empresa activa. Ejecuta el seeder con una empresa seleccionada.');

            return;
        }

        $warehouse = Warehouse::query()->where('is_default', true)->orderBy('id')->first();

        if ($warehouse === null) {
            $this->command?->error('La empresa no tiene almacén por defecto.');

            return;
        }

        $stock = app(StockService::class);
        $creados = 0;

        foreach (self::CATALOG as $categoryName => $items) {
            $category = Category::firstOrCreate(
                ['name' => $categoryName],
                [
                    'slug' => Str::slug($categoryName),
                    'icon' => self::ICONS[$categoryName] ?? null,
                    'is_active' => true,
                ],
            );

            // Las categorías que ya existían de una siembra anterior no tenían icono.
            if ($category->icon === null && isset(self::ICONS[$categoryName])) {
                $category->update(['icon' => self::ICONS[$categoryName]]);
            }

            foreach ($items as [$name, $price]) {
                $sku = self::PREFIX.Str::upper(Str::slug($name));

                if (Product::query()->where('sku', $sku)->exists()) {
                    continue;
                }

                $product = Product::create([
                    'sku' => $sku,
                    'name' => $name,
                    'category_id' => $category->id,
                    'cost' => (string) (int) round($price * 0.55),
                    'price' => (string) $price,
                    'unit' => 'unidad',
                    'track_stock' => true,
                    'is_active' => true,
                ]);

                $product->update(['image_path' => $this->drawImage($name, $categoryName)]);

                $stock->increase($product, $warehouse, StockMovementType::Initial, '40');
                $creados++;
            }
        }

        $this->sembrarOpciones();

        $this->command?->info("Catálogo de demostración listo: {$creados} productos nuevos.");
    }

    /**
     * Tamaños y sabores para los helados, y extras para las hamburguesas: el caso real que motivó
     * los grupos de opciones.
     */
    private function sembrarOpciones(): void
    {
        $tamano = OptionGroup::firstOrCreate(
            ['name' => 'Tamaño'],
            ['selection_type' => SelectionType::Single, 'is_required' => true, 'sort_order' => 0],
        );

        foreach ([['1 bola', '0'], ['2 bolas', '60'], ['3 bolas', '110']] as $orden => [$nombre, $recargo]) {
            Option::firstOrCreate(
                ['option_group_id' => $tamano->id, 'name' => $nombre],
                ['price_delta' => $recargo, 'sort_order' => $orden],
            );
        }

        $sabor = OptionGroup::firstOrCreate(
            ['name' => 'Sabor'],
            [
                'selection_type' => SelectionType::Multiple,
                'is_required' => true,
                'min_selections' => 1,
                'max_selections' => 3,
                'sort_order' => 1,
            ],
        );

        $sabores = ['Vainilla', 'Chocolate', 'Fresa', 'Coco', 'Mango', 'Pistacho', 'Cookies'];
        foreach ($sabores as $orden => $nombre) {
            Option::firstOrCreate(
                ['option_group_id' => $sabor->id, 'name' => $nombre],
                ['price_delta' => '0', 'sort_order' => $orden],
            );
        }

        $extras = OptionGroup::firstOrCreate(
            ['name' => 'Extras'],
            ['selection_type' => SelectionType::Multiple, 'sort_order' => 2],
        );

        foreach ([['Queso extra', '40'], ['Tocineta', '60'], ['Sin cebolla', '0']] as $orden => [$nombre, $recargo]) {
            Option::firstOrCreate(
                ['option_group_id' => $extras->id, 'name' => $nombre],
                ['price_delta' => $recargo, 'sort_order' => $orden],
            );
        }

        // Helados y paletas eligen tamaño y sabor; las hamburguesas, extras.
        Product::query()
            ->whereHas('category', fn ($q) => $q->whereIn('name', ['Helados', 'Paletas']))
            ->where('sku', 'like', self::PREFIX.'%')
            ->get()
            ->each(fn (Product $p) => $p->syncOptionGroups([$tamano->id, $sabor->id]));

        Product::query()
            ->whereHas('category', fn ($q) => $q->where('name', 'Hamburguesas'))
            ->where('sku', 'like', self::PREFIX.'%')
            ->get()
            ->each(fn (Product $p) => $p->syncOptionGroups([$extras->id]));
    }

    /**
     * Dibuja una foto de relleno: degradado con el color de la categoría y las iniciales del
     * producto. Devuelve la ruta guardada, o null si GD no está disponible.
     */
    private function drawImage(string $name, string $categoryName): ?string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagejpeg')) {
            return null;
        }

        [$from, $to] = self::PALETTES[$categoryName] ?? [[99, 102, 241], [129, 140, 248]];

        $size = 400;
        $img = imagecreatetruecolor($size, $size);

        // Degradado vertical, línea a línea.
        for ($y = 0; $y < $size; $y++) {
            $t = $y / ($size - 1);
            $color = imagecolorallocate(
                $img,
                (int) round($from[0] + ($to[0] - $from[0]) * $t),
                (int) round($from[1] + ($to[1] - $from[1]) * $t),
                (int) round($from[2] + ($to[2] - $from[2]) * $t),
            );
            imageline($img, 0, $y, $size, $y, $color);
        }

        // Iniciales centradas, con la fuente interna de GD escalada.
        $initials = Str::upper(collect(explode(' ', $name))
            ->filter()->take(2)->map(fn (string $w): string => mb_substr($w, 0, 1))->implode(''));

        $white = imagecolorallocate($img, 255, 255, 255);
        $scale = 8;
        $charW = imagefontwidth(5) * $scale;
        $charH = imagefontheight(5) * $scale;

        // imagestring no escala, así que se dibuja en pequeño y se amplía sobre el degradado.
        $tmp = imagecreatetruecolor(imagefontwidth(5) * mb_strlen($initials), imagefontheight(5));
        imagefilledrectangle($tmp, 0, 0, imagesx($tmp), imagesy($tmp), imagecolorallocate($tmp, 0, 0, 0));
        imagecolortransparent($tmp, imagecolorallocate($tmp, 0, 0, 0));
        imagestring($tmp, 5, 0, 0, $initials, $white);

        imagecopyresampled(
            $img, $tmp,
            (int) (($size - $charW * mb_strlen($initials)) / 2), (int) (($size - $charH) / 2),
            0, 0,
            $charW * mb_strlen($initials), $charH,
            imagesx($tmp), imagesy($tmp),
        );

        ob_start();
        imagejpeg($img, null, 82);
        $bytes = (string) ob_get_clean();

        imagedestroy($img);
        imagedestroy($tmp);

        $path = 'products/'.Str::ulid()->toBase32().'.jpg';
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }
}
