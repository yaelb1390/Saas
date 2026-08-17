<?php

declare(strict_types=1);

namespace App\Modules\Loans\Http\Controllers;

use App\Modules\CRM\DTOs\CreateCustomerData;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Services\CrmService;
use App\Modules\Loans\DTOs\CreateApplicationData;
use App\Modules\Loans\Enums\InstallmentStatus;
use App\Modules\Loans\Enums\LoanApplicationStatus;
use App\Modules\Loans\Enums\LoanFrequency;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Http\Requests\DecideApplicationRequest;
use App\Modules\Loans\Http\Requests\EvaluateApplicationRequest;
use App\Modules\Loans\Http\Requests\StoreApplicationRequest;
use App\Modules\Loans\Models\Loan;
use App\Modules\Loans\Models\LoanApplication;
use App\Modules\Loans\Services\LoanApplicationService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Solicitudes de préstamo en el panel. Delgado: valida (Form Request), delega en el servicio y
 * traduce las reglas de dominio a mensajes. El route model binding resuelve `{application}` ya
 * aislada por la empresa activa (una solicitud de otra empresa devuelve 404).
 */
final class LoanApplicationController extends Controller
{
    public function index(): View
    {
        $solicitudes = LoanApplication::query()
            ->with('customer')
            ->when(request('q'), function ($query, $q): void {
                // Mismo criterio que el listado de préstamos: código, nombre y cédula, comparando la
                // cédula también sin guiones ni espacios para encontrarla se escriba «001-1909443-4»
                // o «0011909434». REPLACE es portable entre PostgreSQL y SQLite.
                $digitos = preg_replace('/\D/', '', (string) $q);

                $query->where(function ($sub) use ($q, $digitos): void {
                    $sub->whereLike('code', "%{$q}%")
                        ->orWhereLike('customer_name', "%{$q}%")
                        ->orWhereHas('customer', function ($c) use ($q, $digitos): void {
                            $c->whereLike('cedula', "%{$q}%");
                            if ($digitos !== '' && $digitos !== null) {
                                $c->orWhereRaw("REPLACE(REPLACE(cedula, '-', ''), ' ', '') LIKE ?", ["%{$digitos}%"]);
                            }
                        });
                });
            })
            ->when(
                request('estado') && LoanApplicationStatus::tryFrom((string) request('estado')),
                fn ($query) => $query->where('status', request('estado')),
            )
            ->latest()
            ->paginate(15)
            ->withQueryString();

        // Cuántas hay en cada estado, en UNA sola consulta agrupada.
        //
        // Sin esto hay que ir pestaña por pestaña para descubrir dónde está el trabajo, y con la
        // empresa recién estrenada las seis salen vacías y parece que la pantalla no funciona. La
        // cifra al lado del nombre responde eso antes de pulsar nada.
        $porEstado = LoanApplication::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('panel.loan-applications', [
            'applications' => $solicitudes,
            // Igual que al facturar: archivado es archivado en todas partes.
            'customers' => Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'cedula']),
            'frequencies' => LoanFrequency::cases(),
            'statuses' => LoanApplicationStatus::cases(),
            'estadoActivo' => request('estado'),
            'porEstado' => $porEstado,
            // «No hay ninguna» y «ninguna coincide con el filtro» son cosas distintas, y decir la
            // segunda cuando pasa la primera manda al cliente a buscar un filtro que no existe.
            'hayAlguna' => $porEstado->sum() > 0,
        ]);
    }

    public function show(LoanApplication $application): View
    {
        $application->load(['customer', 'loan', 'user', 'decider']);

        return view('panel.loan-application', [
            'application' => $application,
            'historial' => $this->historialDelCliente($application),
        ]);
    }

    public function store(StoreApplicationRequest $request, LoanApplicationService $solicitudes, CrmService $crm): RedirectResponse
    {
        $datos = $request->validated();

        // Cliente nuevo escrito a mano: se registra al vuelo, igual que al crear un préstamo.
        if (empty($datos['customer_id']) && ! empty($datos['new_customer_name'])) {
            $cliente = $crm->createCustomer(new CreateCustomerData(
                name: (string) $datos['new_customer_name'],
                phone: $datos['new_customer_phone'] ?? null,
                cedula: $datos['new_customer_cedula'] ?? null,
            ));
            $datos['customer_id'] = $cliente->id;
        }

        try {
            $solicitud = $solicitudes->create(CreateApplicationData::fromArray($datos));
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return redirect()
            ->route('panel.loan-applications.show', $solicitud)
            ->with('panel_ok', "Solicitud {$solicitud->code} registrada.");
    }

    public function evaluate(EvaluateApplicationRequest $request, LoanApplication $application, LoanApplicationService $solicitudes): RedirectResponse
    {
        try {
            $solicitudes->evaluate($application, $request->validated());
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Evaluación guardada.');
    }

    public function approve(DecideApplicationRequest $request, LoanApplication $application, LoanApplicationService $solicitudes): RedirectResponse
    {
        $datos = $request->validated();

        try {
            $solicitud = $solicitudes->approve($application, [
                'principal' => $datos['principal'] ?? null,
                'installments_count' => $datos['installments_count'] ?? null,
                'interest_rate' => $datos['interest_rate'] ?? null,
                'notes' => $datos['notes'] ?? null,
            ]);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        // Se dice expresamente que el dinero no ha salido: aprobado y desembolsado son dos cosas, y
        // quien viene de un sistema donde eran una sola da por hecho que ya está entregado.
        return back()->with('panel_ok', "Solicitud {$solicitud->code} aprobada. El dinero aún no ha salido: falta desembolsarla.");
    }

    public function reject(DecideApplicationRequest $request, LoanApplication $application, LoanApplicationService $solicitudes): RedirectResponse
    {
        try {
            $solicitud = $solicitudes->reject($application, $request->validated()['notes'] ?? null);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Solicitud {$solicitud->code} rechazada.");
    }

    public function reopen(LoanApplication $application, LoanApplicationService $solicitudes): RedirectResponse
    {
        try {
            $solicitudes->reopen($application);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Solicitud devuelta a evaluación.');
    }

    public function cancelApplication(DecideApplicationRequest $request, LoanApplication $application, LoanApplicationService $solicitudes): RedirectResponse
    {
        try {
            $solicitud = $solicitudes->cancel($application, $request->validated()['notes'] ?? null);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Solicitud {$solicitud->code} marcada como desistida.");
    }

    /**
     * Entrega el dinero. Es la única acción de la pantalla que mueve caja.
     */
    public function disburse(LoanApplication $application, LoanApplicationService $solicitudes): RedirectResponse
    {
        try {
            $prestamo = $solicitudes->disburse($application);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return redirect()
            ->route('panel.loans.show', $prestamo)
            ->with('panel_ok', "Préstamo {$prestamo->code} desembolsado desde la solicitud {$application->code}.");
    }

    /**
     * Cómo se ha portado este cliente con la casa.
     *
     * Es el dato más útil de toda la evaluación y el sistema YA lo tenía: `Customer::loans()` existe
     * desde siempre y no lo miraba ninguna pantalla. Un ingreso declarado es la palabra del cliente;
     * esto es lo que hizo de verdad con el dinero de la casa.
     *
     * Se distinguen dos atrasos porque no dicen lo mismo:
     *
     *  - `en_atraso_hoy`: cuotas que debe AHORA MISMO y ya vencieron. Es deuda viva.
     *  - `pagadas_tarde`: cuotas que acabó pagando, pero después de la fecha. Es su costumbre.
     *
     * Un cliente sin nada en atraso hoy pero con veinte cuotas pagadas tarde no es un buen pagador:
     * es alguien a quien hay que perseguir. Sumar las dos cifras en una sola escondería justo eso.
     *
     * @return array{prestamos: int, saldados: int, vigentes: int, en_atraso_hoy: int, pagadas_tarde: int, saldo_vivo: string}
     */
    private function historialDelCliente(LoanApplication $application): array
    {
        $prestamos = Loan::query()
            ->where('customer_id', $application->customer_id)
            // El préstamo que salió de esta misma solicitud no es antecedente de sí mismo.
            ->when($application->loan_id !== null, fn ($q) => $q->whereKeyNot($application->loan_id))
            ->withCount([
                'installments as en_atraso_hoy' => fn ($q) => $q
                    ->where('status', '!=', InstallmentStatus::Paid->value)
                    ->whereDate('due_date', '<', now()->toDateString()),
                'installments as pagadas_tarde' => fn ($q) => $q
                    ->where('status', InstallmentStatus::Paid->value)
                    ->whereNotNull('paid_at')
                    ->whereColumn('paid_at', '>', 'due_date'),
            ])
            ->get(['id', 'status', 'balance']);

        return [
            'prestamos' => $prestamos->count(),
            'saldados' => $prestamos->where('status', LoanStatus::Paid)->count(),
            'vigentes' => $prestamos->where('status', LoanStatus::Active)->count(),
            'en_atraso_hoy' => (int) $prestamos->sum('en_atraso_hoy'),
            'pagadas_tarde' => (int) $prestamos->sum('pagadas_tarde'),
            'saldo_vivo' => $prestamos
                ->where('status', LoanStatus::Active)
                ->reduce(fn (string $suma, Loan $p): string => bcadd($suma, (string) $p->balance, 2), '0.00'),
        ];
    }
}
