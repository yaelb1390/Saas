<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application;

use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use Carbon\CarbonImmutable;

/**
 * Cifras de emisión de los últimos días en el ambiente actual: por día (aceptados, rechazados,
 * pendientes) y por tipo (documentos, total, ITBIS). Dos consultas agrupadas; nada por documento.
 *
 * Los rechazados y anulados no suman importes: no tienen validez fiscal.
 */
final class EmissionStats
{
    /**
     * @return array{dias: list<string>, aceptados: list<int>, rechazados: list<int>, pendientes: list<int>,
     *               tipos: list<array{tipo: string, documentos: int, total: string, itbis: string}>,
     *               totales: array{documentos: int, total: string, itbis: string, aceptacion: ?int}}
     */
    public function forDays(int $companyId, Environment $env, int $days = 30): array
    {
        $desde = CarbonImmutable::today()->subDays($days - 1);

        $porDia = ElectronicInvoice::query()
            ->where('company_id', $companyId)
            ->where('environment', $env->value)
            ->where('issue_date', '>=', $desde->toDateString())
            // Alias propios: con los nombres de columna, el modelo convertiría `status` en enum y la
            // comparación con el texto no casaría.
            ->selectRaw('issue_date as dia, status as estado, count(*) as n')
            ->groupBy('issue_date', 'status')
            ->toBase()
            ->get();

        $aceptado = [EcfStatus::Aceptado->value, EcfStatus::AceptadoCondicional->value];
        $dias = $aceptados = $rechazados = $pendientes = [];

        for ($d = $desde; $d->lte(CarbonImmutable::today()); $d = $d->addDay()) {
            $filas = $porDia->filter(fn ($f) => CarbonImmutable::parse($f->dia)->isSameDay($d));
            $dias[] = $d->format('d/m');
            $aceptados[] = (int) $filas->whereIn('estado', $aceptado)->sum('n');
            $rechazados[] = (int) $filas->where('estado', EcfStatus::Rechazado->value)->sum('n');
            $pendientes[] = (int) $filas->whereNotIn('estado', [...$aceptado, EcfStatus::Rechazado->value, EcfStatus::Anulado->value])->sum('n');
        }

        $porTipo = ElectronicInvoice::query()
            ->where('company_id', $companyId)
            ->where('environment', $env->value)
            ->where('issue_date', '>=', $desde->toDateString())
            ->whereNotIn('status', [EcfStatus::Rechazado->value, EcfStatus::Anulado->value])
            ->selectRaw('ecf_type as tipo, count(*) as n, sum(total) as total, sum(itbis_total) as itbis')
            ->groupBy('ecf_type')
            ->orderBy('ecf_type')
            ->toBase()
            ->get();

        $tipos = $porTipo->map(fn ($f): array => [
            'tipo' => ((int) $f->tipo).' · '.(EcfType::tryFrom((int) $f->tipo)?->label() ?? ''),
            'documentos' => (int) $f->n,
            'total' => number_format((float) $f->total, 2, '.', ''),
            'itbis' => number_format((float) $f->itbis, 2, '.', ''),
        ])->values()->all();

        $resueltos = array_sum($aceptados) + array_sum($rechazados);

        return [
            'dias' => $dias,
            'aceptados' => $aceptados,
            'rechazados' => $rechazados,
            'pendientes' => $pendientes,
            'tipos' => $tipos,
            'totales' => [
                'documentos' => array_sum($aceptados) + array_sum($rechazados) + array_sum($pendientes),
                'total' => number_format((float) $porTipo->sum('total'), 2, '.', ''),
                'itbis' => number_format((float) $porTipo->sum('itbis'), 2, '.', ''),
                'aceptacion' => $resueltos > 0 ? (int) round(100 * array_sum($aceptados) / $resueltos) : null,
            ],
        ];
    }
}
