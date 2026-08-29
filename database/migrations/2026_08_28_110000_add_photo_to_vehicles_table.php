<?php

declare(strict_types=1);

use App\Modules\Core\Support\DbTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto de la unidad.
 *
 * Una sola por vehículo, no una galería: en la lista se enseña una miniatura, y quien quiera ver el
 * carro entero va a verlo. Una galería es otra tabla y otra pantalla de gestión, y no hace falta
 * para lo que resuelve esto —reconocer la unidad de un vistazo en vez de leer chasis—.
 *
 * Se guarda la RUTA, no la imagen: el fichero vive en el disco (local en desarrollo, Supabase en
 * producción), que es lo que ya hace la foto de producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La tabla puede no existir todavía: aquí las migraciones se aplican a mano y esta pudo
        // llegar antes que la del módulo si alguien las corre sueltas.
        if (! Schema::hasTable('vehicles') || Schema::hasColumn('vehicles', 'photo_path')) {
            return;
        }

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('plate');
        });

        DbTable::olvidar();
    }

    public function down(): void
    {
        if (Schema::hasTable('vehicles') && Schema::hasColumn('vehicles', 'photo_path')) {
            Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn('photo_path'));
        }
    }
};
