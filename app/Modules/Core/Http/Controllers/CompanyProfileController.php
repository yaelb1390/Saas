<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Http\Requests\UpdateCompanyProfileRequest;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Support\CompanyLogoStore;
use App\Modules\Core\Support\EntregaDeArchivo;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * Los datos de la empresa: los que salen impresos en cada recibo que recibe un cliente.
 *
 * Existían en la base de datos desde el principio —dirección, teléfono, razón social, logo— y el
 * recibo ya los imprimía si estaban rellenos. Lo que faltaba era la pantalla para rellenarlos, así
 * que en la práctica todos los recibos salían con el nombre y poco más.
 */
final class CompanyProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('panel.company-profile', [
            'company' => $this->empresa($request),
        ]);
    }

    public function update(UpdateCompanyProfileRequest $request, CompanyLogoStore $logos): RedirectResponse
    {
        $empresa = $this->empresa($request);
        $datos = $request->validated();

        // Las casillas no marcadas NO llegan en la petición, así que se recorre el catálogo entero y
        // lo ausente se guarda como apagado. Leer solo lo que llega dejaría imposible desmarcar.
        $ajustes = $empresa->settings ?? [];
        $enviadas = $datos['features'] ?? [];

        foreach (array_keys(Company::FEATURES) as $clave) {
            $ajustes['features'][$clave] = (bool) ($enviadas[$clave] ?? false);
        }

        $empresa->update([
            'name' => $datos['name'],
            'legal_name' => $datos['legal_name'] ?? null,
            'tax_id' => $datos['tax_id'] ?? null,
            'phone' => $datos['phone'] ?? null,
            'email' => $datos['email'] ?? null,
            'address' => $datos['address'] ?? null,
            'settings' => $ajustes,
        ]);

        if ($request->hasFile('logo')) {
            try {
                $logos->store($empresa, $request->file('logo'));
            } catch (Throwable $e) {
                report($e);

                // Los datos ya se guardaron; solo falló el logo. Se dice exactamente eso en vez de
                // un error genérico que haría pensar que no se guardó nada.
                return back()->with('panel_error', 'Los datos se guardaron, pero el logo no se pudo subir. Inténtalo de nuevo con una imagen más pequeña.');
            }
        }

        return back()->with('panel_ok', 'Datos de la empresa actualizados.');
    }

    public function deleteLogo(Request $request, CompanyLogoStore $logos): RedirectResponse
    {
        $logos->delete($this->empresa($request));

        return back()->with('panel_ok', 'Logo eliminado. Los recibos volverán a salir solo con el nombre.');
    }

    /**
     * Sirve el logo para el recibo que se mira en pantalla.
     *
     * Va por una ruta propia y no por una URL pública del disco porque el logo vive en el mismo
     * almacén privado que las fotos de producto: así el fichero no queda expuesto a quien adivine su
     * nombre, y de paso el aislamiento por empresa lo da la sesión.
     */
    public function logo(Request $request): Response
    {
        $empresa = $this->empresa($request);

        abort_unless($empresa->hasLogo(), 404);

        $ruta = (string) $empresa->logo_path;

        return EntregaDeArchivo::imagen(
            CompanyLogoStore::disk(),
            $ruta,
            str_ends_with($ruta, '.png') ? 'image/png' : 'image/jpeg',
            // Un año: la dirección lleva la marca de tiempo detrás, así que al cambiar el logo cambia
            // la URL y la caché vieja deja de usarse sola. Esto vale para el FICHERO; si se acaba
            // firmando, la entrega recorta sola lo que se guarda de la redirección, que no puede
            // durar más que la firma.
            EntregaDeArchivo::CACHE_ANIO,
        );
    }

    private function empresa(Request $request): Company
    {
        $empresa = app(CurrentCompany::class)->model();

        abort_if($empresa === null, 404);

        return $empresa;
    }
}
