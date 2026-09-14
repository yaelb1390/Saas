<?php

declare(strict_types=1);

namespace App\Modules\Printing\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La impresora predeterminada de UN usuario dentro de UNA empresa.
 *
 * Existe aparte de `printers` porque la predeterminada no es una propiedad de la empresa: es de quien
 * está frente a la caja. El cajero de la caja 2 no hereda la de la caja 1 solo por trabajar en el
 * mismo negocio — cada quien marca la suya.
 */
final class PrinterPreference extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'user_id',
        'default_printer_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Printer, $this>
     */
    public function defaultPrinter(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'default_printer_id');
    }
}
