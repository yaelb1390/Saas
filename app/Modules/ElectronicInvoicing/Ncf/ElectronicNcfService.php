<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Ncf;

use App\Modules\Core\Tenancy\CompanyScope;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfRelease;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use Illuminate\Support\Facades\DB;

/**
 * Asigna e-NCF sin duplicados ni saltos, por empresa, ambiente y tipo.
 *
 * Mismo planteamiento que `Billing\Services\FiscalSequenceService` (transacción + bloqueo de la
 * fila) pero sobre su propia tabla y con dos reglas que la serie B no tiene:
 *
 *   · el ambiente es parte de la secuencia: un número de pruebas nunca sale de producción ni al revés;
 *   · un número que la DGII rechazó con `secuenciaUtilizada = false` puede reutilizarse [DT p.24],
 *     y se reutiliza ANTES de abrir uno nuevo, para no dejar huecos en el rango.
 */
final class ElectronicNcfService
{
    /**
     * Reserva el siguiente e-NCF.
     *
     * @return array{sequence: ElectronicNcfSequence, encf: string, reused: bool}
     */
    public function allocate(int $companyId, Environment $env, EcfType $type): array
    {
        return DB::transaction(function () use ($companyId, $env, $type): array {
            $liberado = ElectronicNcfRelease::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->where('environment', $env->value)
                ->where('ecf_type', $type->value)
                ->whereNull('reused_at')
                ->orderBy('number')
                ->lockForUpdate()
                ->get();

            foreach ($liberado as $release) {
                $secuencia = ElectronicNcfSequence::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->find($release->electronic_ncf_sequence_id);

                // Un número de una secuencia ya vencida no se puede usar: la DGII lo rechazaría.
                if ($secuencia === null || $secuencia->isExpired()) {
                    continue;
                }

                $release->forceFill(['reused_at' => now()])->save();

                return ['sequence' => $secuencia, 'encf' => $this->format($type, $release->number), 'reused' => true];
            }

            $secuencias = ElectronicNcfSequence::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->where('environment', $env->value)
                ->where('ecf_type', $type->value)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($secuencias->isEmpty()) {
                throw ElectronicNcfException::noActiveSequence($type, $env);
            }

            $usable = $secuencias->first(fn (ElectronicNcfSequence $s): bool => ! $s->isExpired() && $s->hasAvailableNumbers());

            if ($usable === null) {
                throw $secuencias->every(fn (ElectronicNcfSequence $s): bool => $s->isExpired())
                    ? ElectronicNcfException::expired($type)
                    : ElectronicNcfException::exhausted($type);
            }

            $numero = $usable->next_number;
            $usable->next_number = $numero + 1;
            $usable->save();

            return ['sequence' => $usable, 'encf' => $this->format($type, $numero), 'reused' => false];
        });
    }

    /**
     * Devuelve al uso un e-NCF que la DGII rechazó con `secuenciaUtilizada = false`.
     *
     * Solo lo llama quien procesa la respuesta de la DGII con ese indicador: liberar un número que la
     * DGII sí dio por usado produciría un duplicado. Idempotente: liberarlo dos veces no lo duplica.
     */
    public function release(int $companyId, Environment $env, string $encf, string $reason): ElectronicNcfRelease
    {
        ['type' => $type, 'number' => $numero] = $this->parse($encf);

        $secuencia = ElectronicNcfSequence::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('environment', $env->value)
            ->where('ecf_type', $type->value)
            ->where('range_from', '<=', $numero)
            ->where('range_to', '>=', $numero)
            ->orderBy('id')
            ->first();

        // Un número que nunca se entregó (aún no se ha llegado a él) tampoco se puede «liberar».
        if ($secuencia === null || $numero >= $secuencia->next_number) {
            throw ElectronicNcfException::notFromCompany($encf);
        }

        return ElectronicNcfRelease::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->firstOrCreate(
                ['company_id' => $companyId, 'environment' => $env->value, 'ecf_type' => $type->value, 'number' => $numero],
                ['electronic_ncf_sequence_id' => $secuencia->id, 'reason' => mb_substr($reason, 0, 500)],
            );
    }

    /** e-NCF = «E» + tipo (2) + secuencial de 10 dígitos [IT §7]. */
    public function format(EcfType $type, int $number): string
    {
        $digitos = (int) config('ecf.encf.sequence_digits', 10);

        return $type->prefix().str_pad((string) $number, $digitos, '0', STR_PAD_LEFT);
    }

    /** @return array{type: EcfType, number: int} */
    public function parse(string $encf): array
    {
        $serie = preg_quote((string) config('ecf.encf.series', 'E'), '/');
        $digitos = (int) config('ecf.encf.sequence_digits', 10);

        if (preg_match("/^{$serie}(\\d{2})(\\d{{$digitos}})$/", $encf, $partes) !== 1) {
            throw ElectronicNcfException::malformed($encf);
        }

        $tipo = EcfType::tryFrom((int) $partes[1]) ?? throw ElectronicNcfException::malformed($encf);

        return ['type' => $tipo, 'number' => (int) $partes[2]];
    }
}
