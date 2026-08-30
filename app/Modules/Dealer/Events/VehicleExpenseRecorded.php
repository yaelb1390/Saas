<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Events;

use App\Modules\Dealer\Models\VehicleJob;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se anotó un gasto sobre una unidad.
 *
 * Va por evento y no llamando a Finanzas directamente para que el Dealer no dependa de Finanzas: un
 * dealer que no tenga ese módulo contratado registra sus gastos igual, y quien sí lo tenga ve el
 * egreso en su cuenta sin que el servicio del patio sepa nada de contabilidad.
 *
 * Es el mismo camino que usan los préstamos con `LoanDisbursed`.
 */
final class VehicleExpenseRecorded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly VehicleJob $expense) {}
}
