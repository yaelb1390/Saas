<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Services\CompanyEraser;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Borrado definitivo de una empresa, con todos sus datos.
 *
 * Es la acción más destructiva del sistema y no tiene vuelta atrás. Por eso exige DOS
 * confirmaciones, que protegen de cosas distintas:
 *
 *  - La CONTRASEÑA responde a «¿eres tú?». Una sesión abierta y desatendida, o una pestaña robada,
 *    no bastan para borrar una empresa entera.
 *  - El NOMBRE de la empresa responde a «¿es esta?». En esta misma plataforma conviven «Prestamos BM»
 *    y «PrestamosFM»: un clic en la tarjeta equivocada sería catastrófico, y ningún «¿estás seguro?»
 *    lo evita, porque a esa pregunta se contesta que sí sin leerla.
 */
final class CompanyDeletionController extends Controller
{
    public function __invoke(
        Request $request,
        Company $company,
        CompanyEraser $eraser,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $request->validate([
            'password' => ['required', 'string'],
            'confirm_name' => ['required', 'string'],
        ], [], [
            'password' => 'contraseña',
            'confirm_name' => 'nombre de la empresa',
        ]);

        $operador = $request->user();

        if ($operador === null || ! Hash::check((string) $request->input('password'), (string) $operador->password)) {
            throw ValidationException::withMessages([
                'password' => 'La contraseña no es correcta. No se borró nada.',
            ]);
        }

        // Comparación indulgente con los espacios y las mayúsculas, estricta con lo demás: se busca
        // que el operador haya LEÍDO qué está borrando, no que teclee con precisión de notario.
        if (mb_strtolower(trim((string) $request->input('confirm_name'))) !== mb_strtolower(trim((string) $company->name))) {
            throw ValidationException::withMessages([
                'confirm_name' => 'El nombre no coincide con el de la empresa. No se borró nada.',
            ]);
        }

        $nombre = (string) $company->name;
        $eraDeLaSesion = $currentCompany->id() === (int) $company->id;

        $eraser->erase($company);

        // Si el operador la tenía como empresa activa, la sesión apuntaría a algo que ya no existe.
        if ($eraDeLaSesion) {
            $request->session()->forget('active_company_id');
            $currentCompany->forget();
        }

        return redirect()->route('platform.companies')
            ->with('panel_ok', "«{$nombre}» y todos sus datos se eliminaron definitivamente.");
    }
}
