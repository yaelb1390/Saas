<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Cash\Enums\CashMovementType;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Finance\DTOs\CreateExpenseData;
use App\Modules\Finance\Enums\AccountType;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Anota y anula gastos.
 *
 * Un gasto toca hasta DOS sitios, y esa es toda la dificultad del módulo:
 *
 *   1. La CUENTA de la que sale (siempre). Es el dinero de la empresa.
 *   2. El CAJÓN, solo si la cuenta es de efectivo y hay un turno abierto. Es el dinero físico.
 *
 * Lo segundo no es un adorno: si pagas al mensajero con el efectivo del cajón y el turno no se
 * entera, al cerrar la caja falta ese dinero y el cierre lo canta como faltante. El cajero acaba
 * cuadrando a mano y el arqueo deja de valer para lo único que sirve: detectar un faltante de
 * verdad.
 *
 * Todo el dinero se maneja con bcmath a 2 decimales, nunca float.
 */
final class ExpenseService
{
    private const SCALE = 2;

    public function __construct(
        private readonly FinanceService $finance,
        private readonly CashService $cash,
    ) {}

    public function create(CreateExpenseData $data): Expense
    {
        return DB::transaction(function () use ($data): Expense {
            $companyId = app(CurrentCompany::class)->id() ?? 0;

            $monto = $this->normalize($data->amount);
            if (bccomp($monto, '0', self::SCALE) <= 0) {
                throw FinanceException::invalidAmount();
            }

            $cuenta = $this->resolveAccount($data->accountId, $companyId);
            $categoria = $this->resolveCategory($data->categoryId, $companyId);
            $proveedor = $this->resolveSupplier($data->supplierId, $companyId);

            $gasto = new Expense([
                'company_id' => $companyId,
                'code' => $this->nextCode($companyId),
                'account_id' => $cuenta->id,
                'expense_category_id' => $categoria->id,
                'supplier_id' => $proveedor?->id,
                // Snapshot del nombre: si mañana se borra el proveedor, el gasto sigue diciendo a
                // quién se le pagó.
                'supplier_name' => $proveedor?->name ?? $data->supplierName,
                'amount' => $monto,
                'description' => $data->description,
                'reference' => $data->reference,
                'paid_at' => $data->paidAt,
                'notes' => $data->notes,
                'user_id' => auth()->id(),
            ]);
            $gasto->save();

            // 1. La cuenta. Siempre.
            $this->finance->record(
                $cuenta,
                MovementType::Expense,
                $monto,
                $this->concepto($gasto, $categoria),
                ['reference' => $gasto, 'occurredAt' => $gasto->paid_at],
            );

            // 2. El cajón, si procede.
            $sesion = $this->sesionAfectada($cuenta);

            if ($sesion !== null) {
                $this->cash->registerMovement($sesion, CashMovementType::Expense, $monto, [
                    'reference' => $gasto,
                    'notes' => $this->concepto($gasto, $categoria),
                ]);
            }

            return $gasto;
        });
    }

    /**
     * Deshace un gasto: borra sus dos apuntes y devuelve el dinero al saldo.
     *
     * Se BORRAN los apuntes en vez de dejar un contraasiento, que es lo que ya hace el proyecto al
     * anular una venta. Coherencia por encima de ortodoxia contable: dos criterios distintos para
     * deshacer dinero en la misma pantalla confundirían más de lo que aclararían.
     *
     * Lo que NO se puede deshacer es un gasto de un turno ya cerrado: aquel arqueo se contó y se
     * firmó con ese dinero fuera, y quitarlo ahora dejaría el cierre diciendo una cifra que nadie
     * contó.
     */
    public function void(Expense $gasto): void
    {
        DB::transaction(function () use ($gasto): void {
            /** @var Expense $gasto */
            $gasto = Expense::query()->whereKey($gasto->id)->lockForUpdate()->firstOrFail();

            $apunteCaja = $gasto->cashMovement()->with('cashSession')->first();

            if ($apunteCaja !== null && ! $apunteCaja->cashSession?->isOpen()) {
                throw FinanceException::cashSessionClosed();
            }

            // La cuenta: se devuelve el importe y se retira el apunte.
            $apunte = $gasto->movement()->first();

            if ($apunte !== null) {
                /** @var Account $cuenta */
                $cuenta = Account::query()->whereKey($apunte->account_id)->lockForUpdate()->firstOrFail();

                // El importe se guarda CON SIGNO (un gasto es negativo), así que restarlo lo suma.
                $cuenta->balance = bcsub((string) $cuenta->balance, (string) $apunte->amount, self::SCALE);
                $cuenta->save();

                $apunte->delete();
            }

            $apunteCaja?->delete();

            $gasto->delete();
        });
    }

    /**
     * El turno de caja al que hay que descontarle este gasto, o null si no hay ninguno.
     *
     * Solo cuando la cuenta es de EFECTIVO: un pago con la cuenta del banco no toca el cajón por
     * mucho que haya un turno abierto.
     */
    private function sesionAfectada(Account $cuenta): ?CashSession
    {
        if ($cuenta->type !== AccountType::Cash) {
            return null;
        }

        return CashSession::query()
            ->where('status', CashSessionStatus::Open)
            ->latest('opened_at')
            ->first();
    }

    /** Texto del apunte: se lee en el listado de movimientos, donde no hay más contexto. */
    private function concepto(Expense $gasto, ExpenseCategory $categoria): string
    {
        return "{$categoria->name}: {$gasto->description}";
    }

    private function resolveAccount(int $accountId, int $companyId): Account
    {
        /** @var Account|null $cuenta */
        $cuenta = Account::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereKey($accountId)
            ->first();

        if ($cuenta === null) {
            throw FinanceException::accountNotInCompany();
        }

        if (! $cuenta->is_active) {
            throw FinanceException::accountInactive($cuenta->name);
        }

        return $cuenta;
    }

    private function resolveCategory(int $categoryId, int $companyId): ExpenseCategory
    {
        /** @var ExpenseCategory|null $categoria */
        $categoria = ExpenseCategory::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereKey($categoryId)
            ->first();

        if ($categoria === null) {
            throw FinanceException::categoryNotInCompany();
        }

        return $categoria;
    }

    private function resolveSupplier(?int $supplierId, int $companyId): ?Supplier
    {
        if ($supplierId === null) {
            return null;
        }

        /** @var Supplier|null $proveedor */
        $proveedor = Supplier::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereKey($supplierId)
            ->first();

        // Un proveedor de otra empresa se ignora en vez de reventar: el gasto es válido igual y el
        // nombre suelto sigue diciendo a quién se le pagó.
        return $proveedor;
    }

    private function nextCode(int $companyId): string
    {
        $cuantos = Expense::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->count();

        return 'GAS-'.str_pad((string) ($cuantos + 1), 6, '0', STR_PAD_LEFT);
    }

    private function normalize(string $valor): string
    {
        return bcadd($valor === '' ? '0' : $valor, '0', self::SCALE);
    }
}
