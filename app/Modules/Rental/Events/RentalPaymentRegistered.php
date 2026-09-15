<?php

declare(strict_types=1);

namespace App\Modules\Rental\Events;

use App\Modules\Rental\Models\VehicleRentalPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Se disparó por evento y no llamando a Finanzas directamente, mismo motivo que en el Dealer: una
 * empresa que tenga Alquiler sin tener Finanzas registra sus cobros igual, solo que nadie escucha. */
final class RentalPaymentRegistered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly VehicleRentalPayment $payment) {}
}
