<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Listeners;

use App\Modules\Core\Tenancy\CompanyScope;
use App\Modules\Dealer\Events\VehicleExpenseRecorded;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinanceService;
use Throwable;

/**
 * Al anotar un gasto de una unidad, registra el EGRESO en la cuenta por omisión.
 *
 * DEFENSIVO A PROPÓSITO, igual que `RecordLoanDisbursement`: si Finanzas falla —o la empresa no tiene
 * ninguna cuenta creada—, el gasto del vehículo se queda guardado igual. Un fallo contable no puede
 * tumbar la operación que lo originó; el costo real de la unidad es lo que no se puede perder.
 */
final class RecordVehicleExpense
{
    public function __construct(private readonly FinanceService $finance) {}

    public function handle(VehicleExpenseRecorded $event): void
    {
        $gasto = $event->expense;

        if (bccomp((string) $gasto->cost, '0', 2) <= 0) {
            return;
        }

        /*
         * Sin el ámbito de empresa y con el `company_id` explícito.
         *
         * Esto puede correr en una cola, donde no hay empresa activa: con el ámbito puesto, la
         * consulta no encontraría la cuenta y el egreso se perdería en silencio.
         */
        $cuenta = Account::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $gasto->company_id)
            ->where('is_default', true)
            ->first();

        if ($cuenta === null) {
            return;
        }

        try {
            $unidad = $gasto->vehicle;

            $this->finance->record(
                $cuenta,
                MovementType::Expense,
                (string) $gasto->cost,
                trim($gasto->type->label().': '.$gasto->description.($unidad ? ' — '.$unidad->nombre() : '')),
                ['reference' => 'VEH-'.$gasto->vehicle_id],
            );
        } catch (Throwable) {
            // Se traga a propósito: ver el comentario de la clase.
        }
    }
}
