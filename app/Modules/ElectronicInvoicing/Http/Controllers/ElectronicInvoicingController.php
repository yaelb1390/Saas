<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Controllers;

use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Application\RuntimeRequirements;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Http\Requests\StoreElectronicNcfSequenceRequest;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use App\Modules\ElectronicInvoicing\Ncf\ElectronicNcfService;
use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Resumen de Facturación Electrónica de la empresa activa.
 *
 * Solo lee: no habla con la DGII ni con ningún proveedor. Lo que enseña de la conexión es lo último
 * registrado, nunca una llamada hecha al pintar la pantalla.
 */
final class ElectronicInvoicingController extends Controller
{
    public function index(
        CurrentCompany $actual,
        SchemaRegistry $esquemas,
        RuntimeRequirements $requisitos,
        ElectronicNcfService $ncf,
    ): View {
        $empresa = $actual->model();
        abort_if($empresa === null, 404);

        // El código sale antes que la migración (en producción se aplican a mano): sin la tabla, la
        // pantalla se pinta igual y dice qué falta, en vez de un 500.
        $migracionPendiente = ! DbTable::existe('electronic_invoicing_settings')
            || ! DbTable::existe('electronic_ncf_sequences');

        return view('panel.e-invoicing.index', [
            'empresa' => $empresa,
            'ajustes' => DbTable::existe('electronic_invoicing_settings') ? ElectronicInvoicingSettings::paraEmpresa($empresa) : null,
            'secuencias' => DbTable::existe('electronic_ncf_sequences')
                ? ElectronicNcfSequence::query()->orderBy('environment')->orderBy('ecf_type')->orderBy('id')->get()
                : collect(),
            'ambientes' => Environment::cases(),
            'ncf' => $ncf,
            'migracionPendiente' => $migracionPendiente,
            'tipos' => EcfType::cases(),
            'esquemas' => $esquemas->integrity(),
            'esquemasIntegros' => $esquemas->allIntact(),
            'requisitos' => $requisitos->check(),
            'pendientes' => (array) config('ecf.pending_verification', []),
        ]);
    }

    /**
     * Registra un rango de e-NCF que la DGII autorizó a la empresa en su Oficina Virtual.
     *
     * BMIA no pide ni autoriza números: solo anota los que la DGII ya autorizó, para no salirse de
     * ellos al emitir.
     */
    public function storeSequence(StoreElectronicNcfSequenceRequest $request): RedirectResponse
    {
        if (! DbTable::existe('electronic_ncf_sequences')) {
            return back()->with('panel_error', 'Falta aplicar las migraciones de facturación electrónica.');
        }

        $datos = $request->validated();

        ElectronicNcfSequence::create([
            ...$datos,
            'next_number' => (int) $datos['range_from'],
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('panel_ok', 'Secuencia de e-NCF registrada.');
    }
}
