<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Finance\DTOs\CreateExpenseData;
use App\Modules\Finance\Enums\AccountType;
use App\Modules\Finance\Http\Requests\StoreExpenseCategoryRequest;
use App\Modules\Finance\Http\Requests\StoreExpenseRequest;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Services\ExpenseService;
use App\Modules\Purchasing\Models\Supplier;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * Gastos del negocio. Delgado: valida, delega en ExpenseService y traduce las reglas de dominio a
 * mensajes. Todo el movimiento de dinero vive en el servicio.
 */
final class ExpenseController extends Controller
{
    public function index(): View
    {
        [$desde, $hasta] = $this->rango();

        $gastos = Expense::query()
            ->with(['category', 'account', 'supplier'])
            // `whereDate` y no `whereBetween`: la fecha se guarda con hora («2026-08-15 00:00:00»)
            // y comparada como texto contra «2026-08-15» queda POR ENCIMA del límite superior, así
            // que el último día del rango —normalmente hoy— desaparecía del listado. `whereDate`
            // convierte la columna a fecha en SQL y se comporta igual en PostgreSQL y en SQLite.
            ->whereDate('paid_at', '>=', $desde->toDateString())
            ->whereDate('paid_at', '<=', $hasta->toDateString())
            ->when(request('concepto'), fn ($q) => $q->where('expense_category_id', request('concepto')))
            ->when(request('cuenta'), fn ($q) => $q->where('account_id', request('cuenta')))
            ->when(request('q'), fn ($q, $texto) => $q->where(fn ($sub) => $sub
                ->whereLike('description', "%{$texto}%")
                ->orWhereLike('code', "%{$texto}%")
                ->orWhereLike('supplier_name', "%{$texto}%")
                ->orWhereLike('reference', "%{$texto}%")))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // El desglose por concepto es la razón de ser de la pantalla: sin él, esto sería una lista
        // de salidas de dinero que nadie lee. Se calcula sobre el MISMO filtro que la tabla, para
        // que la suma de las barras cuadre con lo que se está viendo.
        $porConcepto = Expense::query()
            ->selectRaw('expense_category_id, sum(amount) as total, count(*) as cuantos')
            // `whereDate` y no `whereBetween`: la fecha se guarda con hora («2026-08-15 00:00:00»)
            // y comparada como texto contra «2026-08-15» queda POR ENCIMA del límite superior, así
            // que el último día del rango —normalmente hoy— desaparecía del listado. `whereDate`
            // convierte la columna a fecha en SQL y se comporta igual en PostgreSQL y en SQLite.
            ->whereDate('paid_at', '>=', $desde->toDateString())
            ->whereDate('paid_at', '<=', $hasta->toDateString())
            ->when(request('concepto'), fn ($q) => $q->where('expense_category_id', request('concepto')))
            ->when(request('cuenta'), fn ($q) => $q->where('account_id', request('cuenta')))
            ->groupBy('expense_category_id')
            ->orderByDesc('total')
            ->with('category')
            ->get();

        $total = $porConcepto->reduce(
            fn (string $suma, $fila): string => bcadd($suma, (string) $fila->total, 2),
            '0.00',
        );

        return view('panel.expenses', [
            'expenses' => $gastos,
            'porConcepto' => $porConcepto,
            'total' => $total,
            'desde' => $desde,
            'hasta' => $hasta,
            'categories' => ExpenseCategory::query()->usables()->get(),
            'accounts' => Account::query()->where('is_active', true)->orderBy('name')->get(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            // Se avisa en la pantalla de si el gasto en efectivo va a descontarse de un turno.
            'sesionAbierta' => CashSession::query()
                ->where('status', CashSessionStatus::Open)->latest('opened_at')->first(),
            'hayCuentaEfectivo' => Account::query()
                ->where('is_active', true)->where('type', AccountType::Cash)->exists(),
        ]);
    }

    public function store(StoreExpenseRequest $request, ExpenseService $gastos): RedirectResponse
    {
        try {
            $gasto = $gastos->create(CreateExpenseData::fromArray($request->validated()));
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        // Se dice si tocó el arqueo o no: es la diferencia entre que el cierre del turno cuadre o
        // que aparezca un faltante que nadie se explica.
        $aviso = $gasto->cashMovement()->exists()
            ? ' Se descontó también del turno de caja abierto.'
            : '';

        return back()->with('panel_ok', "Gasto {$gasto->code} registrado.{$aviso}");
    }

    public function destroy(Expense $expense, ExpenseService $gastos): RedirectResponse
    {
        try {
            $gastos->void($expense);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Gasto {$expense->code} anulado y devuelto al saldo.");
    }

    // --------------------------------------------------------------------- Conceptos de gasto

    public function storeCategory(StoreExpenseCategoryRequest $request): RedirectResponse
    {
        ExpenseCategory::create([
            'name' => $request->validated()['name'],
            'is_active' => true,
        ]);

        return back()->with('panel_ok', 'Concepto creado.');
    }

    public function updateCategory(StoreExpenseCategoryRequest $request, ExpenseCategory $category): RedirectResponse
    {
        $datos = $request->validated();

        $category->update([
            'name' => $datos['name'],
            'is_active' => (bool) ($datos['is_active'] ?? false),
        ]);

        return back()->with('panel_ok', 'Concepto actualizado.');
    }

    /**
     * Rango de fechas del listado. Por defecto, el mes en curso: es el período en el que la gente
     * piensa sus gastos («¿cuánto llevo este mes?»).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rango(): array
    {
        $desde = request('desde')
            ? Carbon::parse((string) request('desde'))->startOfDay()
            : Carbon::now()->startOfMonth();

        $hasta = request('hasta')
            ? Carbon::parse((string) request('hasta'))->endOfDay()
            : Carbon::now()->endOfDay();

        // Un rango al revés no devuelve nada y parece que no hay gastos; se enderezan las fechas.
        return $desde->greaterThan($hasta) ? [$hasta->copy()->startOfDay(), $desde->copy()->endOfDay()] : [$desde, $hasta];
    }
}
