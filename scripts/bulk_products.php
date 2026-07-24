<?php

// Carga masiva de productos ficticios para pruebas de volumen/fluidez.
// Se ejecuta con: php artisan tinker scripts/bulk_products.php (con las env DB apuntando a Supabase).

use Illuminate\Support\Facades\DB;

$now = now();

foreach ([1, 2] as $cid) {
    $wh = DB::table('warehouses')->where('company_id', $cid)->where('is_default', true)->value('id');

    $catId = DB::table('categories')->where('company_id', $cid)->where('name', 'General')->value('id');
    if (! $catId) {
        $catId = DB::table('categories')->insertGetId([
            'company_id' => $cid, 'name' => 'General', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    // Evita duplicar si se corre dos veces: parte del siguiente índice libre.
    $already = DB::table('products')->where('company_id', $cid)->where('sku', 'like', "BULK-{$cid}-%")->count();

    $products = [];
    for ($n = $already + 1; $n <= $already + 500; $n++) {
        $cost = random_int(50, 5000);
        $products[] = [
            'company_id' => $cid,
            'category_id' => $catId,
            'sku' => "BULK-{$cid}-".str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'name' => "Producto {$cid}-{$n}",
            'unit' => 'unidad',
            'cost' => (string) $cost,
            'price' => (string) (int) round($cost * 1.4),
            'track_stock' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($products, 100) as $chunk) {
        DB::table('products')->insert($chunk);
    }

    $ids = DB::table('products')
        ->where('company_id', $cid)
        ->whereIn('sku', array_column($products, 'sku'))
        ->pluck('id');

    $stock = [];
    foreach ($ids as $pid) {
        $stock[] = [
            'company_id' => $cid, 'product_id' => $pid, 'warehouse_id' => $wh,
            'quantity' => (string) random_int(0, 200),
            'created_at' => $now, 'updated_at' => $now,
        ];
    }
    foreach (array_chunk($stock, 100) as $chunk) {
        DB::table('stock')->insert($chunk);
    }

    echo "empresa {$cid}: +".count($products)." productos, +".count($stock)." filas de stock".PHP_EOL;
}

echo 'total productos: '.DB::table('products')->count().PHP_EOL;
echo 'total stock: '.DB::table('stock')->count().PHP_EOL;
