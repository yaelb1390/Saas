<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pasa a la galería las fotos que ya estaban.
 *
 * Antes de la galería, la foto de una unidad se guardaba solo en `vehicles.photo_path`. Sin este
 * trasvase, todo dealer que ya hubiera subido una foto abriría la pestaña «Fotos» y la vería VACÍA
 * mientras la miniatura de la lista sigue enseñando esa misma foto: parecería que el sistema perdió
 * la imagen.
 *
 * Lo detecté abriendo la ficha de una unidad que tenía foto y encontrándome «esta unidad no tiene
 * fotos todavía».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_photos') || ! Schema::hasColumn('vehicles', 'photo_path')) {
            return;
        }

        DB::table('vehicles')
            ->whereNotNull('photo_path')
            ->where('photo_path', '!=', '')
            // Sin las que ya tienen galería: esta migración tiene que poder correrse dos veces sin
            // duplicar nada.
            ->whereNotIn('id', fn ($q) => $q->from('vehicle_photos')->select('vehicle_id'))
            ->orderBy('id')
            // Por lotes: un dealer con miles de unidades no debe cargarlas todas en memoria.
            ->chunkById(200, function ($unidades): void {
                $ahora = now();

                DB::table('vehicle_photos')->insert(
                    collect($unidades)->map(fn ($v): array => [
                        'company_id' => $v->company_id,
                        'vehicle_id' => $v->id,
                        'path' => $v->photo_path,
                        'position' => 0,
                        // La que había ES la principal: es la que la lista viene enseñando.
                        'is_primary' => true,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        // No se deshace: borrar las filas dejaría la galería vacía y la foto seguiría en el disco,
        // que es peor estado que el actual.
    }
};
