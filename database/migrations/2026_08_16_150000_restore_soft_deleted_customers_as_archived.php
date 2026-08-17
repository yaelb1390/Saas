<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los clientes «eliminados» vuelven, archivados.
 *
 * El botón de la papelera hacía un borrado lógico, y el cliente usa `SoftDeletes`. La consecuencia es
 * que cada `belongsTo(Customer::class)` de Ventas, Facturación, Entregas, WhatsApp y Préstamos pasaba
 * a devolver NULL con la clave ajena apuntando a una fila que sigue viva: la ficha de un préstamo de
 * ese cliente reventaba al pedir su nombre.
 *
 * El propio diálogo de confirmación describía otra cosa —«se archiva y deja de aparecer al vender»—,
 * que es exactamente lo que significa `is_active`. Ahora archivar y eliminar son dos acciones
 * distintas, y esta migración pone al día lo que quedó de antes: quien se «eliminó» pasa a estar
 * archivado, que es lo que se quiso hacer.
 *
 * Se prefiere esto a poner `withTrashed()` en las cinco relaciones: deja una sola verdad en lugar de
 * cinco excepciones que alguien tendrá que recordar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null, 'is_active' => false]);
    }

    /**
     * No se deshace.
     *
     * Volver a borrar en blando a quien esta migración recuperó exige saber cuáles estaban borrados
     * antes, y ese dato se pierde al limpiarlo. Además volver atrás significaría volver a romper las
     * relaciones que esto arregla, que es lo último que querría nadie.
     */
    public function down(): void {}
};
