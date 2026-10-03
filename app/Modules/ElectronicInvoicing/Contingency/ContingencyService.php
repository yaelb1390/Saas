<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Contingency;

use App\Modules\Core\Tenancy\CompanyScope;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoiceContingency;

/**
 * Registro de contingencias [IT §19, Decreto 587-24].
 *
 * Solo REGISTRA y AVISA; no inventa procedimientos. Los casos oficiales:
 *   · sin conectividad del emisor → generar y enviar ≤ 72 h tras recuperarla;
 *   · DGII no disponible → almacenar y enviar al restablecerse;
 *   · el emisor no puede generar e-CF → serie B máx. 15 días y regularizar en ≤ 30 días.
 *
 * Un fallo de comunicación abre una contingencia (si no hay otra abierta para esa empresa y
 * ambiente); el primer envío que vuelve a funcionar la cierra. Los documentos emitidos mientras está
 * abierta quedan ligados a ella (leyenda en la representación impresa, fase 6).
 */
final class ContingencyService
{
    public function open(int $companyId, Environment $env): ?ElectronicInvoiceContingency
    {
        return ElectronicInvoiceContingency::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('environment', $env->value)
            ->whereNull('ended_at')
            ->latest('id')
            ->first();
    }

    public function recordFailure(int $companyId, Environment $env, string $reason): ElectronicInvoiceContingency
    {
        return $this->open($companyId, $env) ?? ElectronicInvoiceContingency::create([
            'company_id' => $companyId,
            'environment' => $env,
            'kind' => 'dgii_no_disponible',
            'reason' => mb_substr($reason, 0, 500),
            'started_at' => now(),
        ]);
    }

    public function recordRecovery(int $companyId, Environment $env): void
    {
        $abierta = $this->open($companyId, $env);

        $abierta?->forceFill(['ended_at' => now()])->save();
    }

    /** Horas que lleva abierta, para el aviso de 72 h. */
    public function hoursOpen(ElectronicInvoiceContingency $c): int
    {
        return (int) $c->started_at->diffInHours(now());
    }
}
