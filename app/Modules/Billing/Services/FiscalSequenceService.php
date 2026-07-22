<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Exceptions\FiscalSequenceException;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Core\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Asigna números de comprobante fiscal (NCF) de forma atómica y sin huecos ni duplicados.
 * Bloquea la fila de la secuencia (lockForUpdate) dentro de una transacción para ser seguro
 * ante concurrencia, validando vigencia y disponibilidad del rango autorizado por la DGII.
 */
final class FiscalSequenceService
{
    /**
     * Reserva el siguiente NCF disponible para un tipo de comprobante.
     *
     * @return array{0: FiscalSequence, 1: string} [secuencia, ncf]
     */
    public function allocate(int $companyId, NcfType $type): array
    {
        return DB::transaction(function () use ($companyId, $type): array {
            $sequence = FiscalSequence::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->where('type', $type)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                throw FiscalSequenceException::noActiveSequence($type);
            }

            if ($sequence->isExpired()) {
                throw FiscalSequenceException::expired($type);
            }

            if (! $sequence->hasAvailableNumbers()) {
                throw FiscalSequenceException::exhausted($type);
            }

            $ncf = $sequence->formatNcf($sequence->next_number);
            $sequence->next_number = $sequence->next_number + 1;
            $sequence->save();

            return [$sequence, $ncf];
        });
    }
}
