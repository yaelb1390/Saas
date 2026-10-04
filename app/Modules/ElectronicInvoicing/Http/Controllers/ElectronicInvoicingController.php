<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Controllers;

use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Application\RuntimeRequirements;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\EmissionMode;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Http\Requests\StoreCertificateRequest;
use App\Modules\ElectronicInvoicing\Http\Requests\StoreElectronicNcfSequenceRequest;
use App\Modules\ElectronicInvoicing\Signature\CertificateException;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use App\Modules\ElectronicInvoicing\Ncf\ElectronicNcfService;
use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'certificado' => DbTable::existe('electronic_certificates') ? app(CertificateVault::class)->active($empresa) : null,
            'ambientes' => Environment::cases(),
            'ncf' => $ncf,
            'migracionPendiente' => $migracionPendiente,
            'tipos' => EcfType::cases(),
            'esquemas' => $esquemas->integrity(),
            'esquemasIntegros' => $esquemas->allIntact(),
            'requisitos' => $requisitos->check(),
            'pendientes' => (array) config('ecf.pending_verification', []),
            'modos' => EmissionMode::cases(),
            'modoDisponible' => DbTable::tieneColumna('electronic_invoicing_settings', 'emission_mode')
                && DbTable::tieneColumna('invoices', 'electronic_invoice_id'),
            'contadores' => $this->contadores(),
        ]);
    }

    /**
     * Cambia el modo de emisión (apagado / en paralelo / real).
     *
     * «Real» solo en producción y «en paralelo» nunca en producción (`EmissionMode::allowedIn`).
     * Encender cualquiera de los dos exige un certificado activo: sin él no se puede firmar nada y
     * cada factura dejaría un aviso en el registro.
     */
    public function updateMode(Request $request, CurrentCompany $actual, CertificateVault $vault): RedirectResponse
    {
        if (! DbTable::tieneColumna('electronic_invoicing_settings', 'emission_mode')) {
            return back()->with('panel_error', 'Falta aplicar las migraciones de facturación electrónica.');
        }

        $empresa = $actual->model();
        abort_if($empresa === null, 404);

        $datos = $request->validate(['emission_mode' => ['required', Rule::enum(EmissionMode::class)]]);
        $modo = EmissionMode::from($datos['emission_mode']);
        $ajustes = ElectronicInvoicingSettings::paraEmpresa($empresa);

        if (! $modo->allowedIn($ajustes->environment)) {
            return back()->withErrors(['emission_mode' => "«{$modo->label()}» no está disponible en el ambiente {$ajustes->environment->label()}."]);
        }

        if ($modo !== EmissionMode::Apagado && $vault->active($empresa) === null) {
            return back()->withErrors(['emission_mode' => 'Primero sube el certificado digital: sin él no se puede firmar ningún e-CF.']);
        }

        $ajustes->forceFill(['emission_mode' => $modo->value])->save();

        return back()->with('panel_ok', "Modo de emisión: {$modo->label()}.");
    }

    /** @return array{pendientes: int, aceptados: int, rechazados: int} */
    private function contadores(): array
    {
        if (! DbTable::existe('electronic_invoices')) {
            return ['pendientes' => 0, 'aceptados' => 0, 'rechazados' => 0];
        }

        $porEstado = ElectronicInvoice::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $suma = fn (array $estados): int => (int) collect($estados)->sum(fn (EcfStatus $e) => $porEstado[$e->value] ?? 0);

        return [
            'pendientes' => $suma([EcfStatus::PendienteEnvio, EcfStatus::Enviando, EcfStatus::Recibido, EcfStatus::Contingencia, EcfStatus::Error]),
            'aceptados' => $suma([EcfStatus::Aceptado, EcfStatus::AceptadoCondicional]),
            'rechazados' => $suma([EcfStatus::Rechazado]),
        ];
    }

    /**
     * Sube (o reemplaza) el certificado digital de firma. El anterior queda desactivado, no borrado.
     */
    public function storeCertificate(StoreCertificateRequest $request, CurrentCompany $actual, CertificateVault $vault): RedirectResponse
    {
        if (! DbTable::existe('electronic_certificates')) {
            return back()->with('panel_error', 'Falta aplicar las migraciones de facturación electrónica.');
        }

        $empresa = $actual->model();
        abort_if($empresa === null, 404);

        try {
            $cert = $vault->store(
                $empresa,
                (string) file_get_contents((string) $request->file('certificate')->getRealPath()),
                (string) $request->input('password'),
                $request->user()?->id,
            );
        } catch (CertificateException $e) {
            // El mensaje nunca incluye la contraseña ni la clave.
            return back()->withErrors(['certificate' => $e->getMessage()]);
        }

        return back()->with('panel_ok', "Certificado guardado. Vence el {$cert->valid_to->format('d/m/Y')}.");
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
