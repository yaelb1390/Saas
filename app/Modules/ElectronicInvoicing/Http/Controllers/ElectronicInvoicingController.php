<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Controllers;

use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Application\RuntimeRequirements;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use Illuminate\Contracts\View\View;
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
    ): View {
        $empresa = $actual->model();
        abort_if($empresa === null, 404);

        // El código sale antes que la migración (en producción se aplican a mano): sin la tabla, la
        // pantalla se pinta igual y dice qué falta, en vez de un 500.
        $migracionPendiente = ! DbTable::existe('electronic_invoicing_settings');

        return view('panel.e-invoicing.index', [
            'empresa' => $empresa,
            'ajustes' => $migracionPendiente ? null : ElectronicInvoicingSettings::paraEmpresa($empresa),
            'migracionPendiente' => $migracionPendiente,
            'tipos' => EcfType::cases(),
            'esquemas' => $esquemas->integrity(),
            'esquemasIntegros' => $esquemas->allIntact(),
            'requisitos' => $requisitos->check(),
            'pendientes' => (array) config('ecf.pending_verification', []),
        ]);
    }
}
