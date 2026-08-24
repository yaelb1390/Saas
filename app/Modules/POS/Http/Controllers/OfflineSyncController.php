<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Controllers;

use App\Modules\POS\Services\OfflineSaleSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * La puerta por la que entran las ventas cobradas sin internet.
 *
 * Dos rutas y nada más: una para preguntar si todavía se puede subir algo, y otra para subirlo.
 */
final class OfflineSyncController extends Controller
{
    /**
     * ¿Sigue viva la sesión, y con qué token?
     *
     * El terminal pregunta esto ANTES de subir nada, y existe por un motivo concreto: la copia de la
     * pantalla que guardó el navegador lleva el token CSRF de cuando se guardó, que puede tener
     * horas. Mandar el lote con ese token daría un 419 y el cajero vería fallar el envío sin
     * entender por qué.
     *
     * Si la sesión ha caducado, esta ruta ni siquiera responde: el middleware manda al login, el
     * navegador lo ve, DEJA LA COLA QUIETA y pide iniciar sesión. Perder ventas ya cobradas por una
     * sesión vencida sería el peor final posible.
     */
    public function estado(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'token' => csrf_token(),
        ]);
    }

    /**
     * Sube un lote de ventas.
     *
     * Devuelve una respuesta POR VENTA y no un «ok» global: el navegador necesita saber cuál puede
     * borrar de su cola y cuál tiene que apartar. Un lote donde una venta es imposible sube todas
     * las demás.
     */
    public function sincronizar(Request $request, OfflineSaleSyncService $sync): JsonResponse
    {
        $datos = $request->validate([
            // Un tope al lote: sin él, un terminal con la cola corrompida podría mandar cien mil
            // ventas y tumbar la petición. Cincuenta cubre un día entero de un colmado sin línea.
            'ventas' => ['required', 'array', 'min:1', 'max:50'],
            'ventas.*.uuid' => ['required', 'uuid'],
            'ventas.*.cash_session_id' => ['nullable', 'integer'],
            'ventas.*.payment_method' => ['nullable', 'string', 'max:30'],
            'ventas.*.paid' => ['nullable', 'numeric', 'min:0'],
            'ventas.*.tip' => ['nullable', 'numeric', 'min:0'],
            'ventas.*.discount_total' => ['nullable', 'numeric', 'min:0'],
            'ventas.*.customer_name' => ['nullable', 'string', 'max:255'],
            'ventas.*.customer_id' => ['nullable', 'integer'],
            'ventas.*.employee_id' => ['nullable', 'integer'],
            'ventas.*.order_type' => ['nullable', 'string', 'max:30'],

            'ventas.*.lines' => ['required', 'array', 'min:1', 'max:200'],
            'ventas.*.lines.*.product_id' => ['required', 'integer'],
            'ventas.*.lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            // El precio cobrado. Es lo único de todo el POS que se acepta del navegador, y solo
            // aquí: la venta ya ocurrió y este es el número que tiene el cliente en su recibo.
            // Ver OfflineSaleSyncService, regla 2.
            'ventas.*.lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'ventas.*.lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'ventas.*.lines.*.note' => ['nullable', 'string', 'max:255'],
            'ventas.*.lines.*.serial' => ['nullable', 'string', 'max:100'],
            'ventas.*.lines.*.employee_id' => ['nullable', 'integer'],
            'ventas.*.lines.*.options' => ['nullable', 'array', 'max:20'],
            'ventas.*.lines.*.options.*' => ['integer'],
        ]);

        return response()->json([
            'resultados' => $sync->sincronizar($datos['ventas']),
        ]);
    }
}
