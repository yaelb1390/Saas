<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Controllers;

use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Application\PsfeConnectionService;
use App\Modules\ElectronicInvoicing\Http\Requests\ConnectPsfeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;

/**
 * La tarjeta «Conecta tu proveedor autorizado». Solo traduce peticiones y respuestas: probar,
 * guardar y desconectar lo hace `PsfeConnectionService`.
 *
 * Las credenciales nunca vuelven al formulario (`withInput` las excluye): una clave mal escrita se
 * vuelve a teclear, no se queda en la sesión.
 */
final class PsfeConnectionController extends Controller
{
    public function connect(ConnectPsfeRequest $request, CurrentCompany $actual, PsfeConnectionService $conexion): RedirectResponse
    {
        if (! DbTable::existe('electronic_invoicing_settings')) {
            return back()->with('panel_error', 'Falta aplicar las migraciones de facturación electrónica.');
        }

        $empresa = $actual->model();
        abort_if($empresa === null, 404);

        $datos = $request->validated();

        try {
            $prueba = $conexion->connect($empresa, $datos['psfe'], (array) ($datos['credentials'] ?? []), $request->user()?->id);
        } catch (RuntimeException $e) {
            return $this->volver($e->getMessage());
        }

        return $prueba->ok
            ? redirect()->to(route('panel.e-invoicing').'#proveedor')->with('panel_ok', 'Proveedor conectado. '.$prueba->message)
            : $this->volver('No se conectó: '.$prueba->message);
    }

    public function test(CurrentCompany $actual, PsfeConnectionService $conexion): RedirectResponse
    {
        $empresa = $actual->model();
        abort_if($empresa === null, 404);

        try {
            $prueba = $conexion->test($empresa);
        } catch (RuntimeException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with($prueba->ok ? 'panel_ok' : 'panel_error', $prueba->message);
    }

    public function disconnect(Request $request, CurrentCompany $actual, PsfeConnectionService $conexion): RedirectResponse
    {
        $empresa = $actual->model();
        abort_if($empresa === null, 404);

        $apagada = $conexion->disconnect($empresa, $request->user()?->id);

        return back()->with('panel_ok', $apagada
            ? 'Proveedor desconectado. La emisión de e-CF quedó apagada: no hay con qué firmar.'
            : 'Proveedor desconectado.');
    }

    private function volver(string $motivo): RedirectResponse
    {
        return redirect()->to(route('panel.e-invoicing').'#proveedor')
            ->withInput(request()->except('credentials'))
            ->withErrors(['psfe' => $motivo]);
    }
}
