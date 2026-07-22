<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Services\DgiiReportService;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga de los envíos de datos de la DGII (607 ventas, 608 anulados) como TXT.
 *
 * El nombre del archivo sigue la convención de la DGII: RNC + período + formato.
 */
final class DgiiReportController extends Controller
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function sales607(Request $request, DgiiReportService $reports): StreamedResponse
    {
        $period = $this->period($request);

        return $this->download('607', $period, $reports->sales607($period));
    }

    public function cancelled608(Request $request, DgiiReportService $reports): StreamedResponse
    {
        $period = $this->period($request);

        return $this->download('608', $period, $reports->cancelled608($period));
    }

    /**
     * Período mensual (AAAA-MM). Por defecto, el mes en curso.
     */
    private function period(Request $request): Carbon
    {
        $raw = (string) $request->query('period', '');

        return preg_match('/^\d{4}-\d{2}$/', $raw) === 1
            ? Carbon::createFromFormat('Y-m', $raw)->startOfMonth()
            : Carbon::now()->startOfMonth();
    }

    private function download(string $format, Carbon $period, string $contents): StreamedResponse
    {
        $companyId = $this->currentCompany->id();
        $taxId = $companyId === null
            ? null
            : Company::query()->whereKey($companyId)->value('tax_id');

        $rnc = preg_replace('/\D/', '', (string) $taxId) ?: 'SIN-RNC';
        $filename = "DGII_{$format}_{$rnc}_{$period->format('Ym')}.txt";

        return response()->streamDownload(
            function () use ($contents): void {
                echo $contents;
            },
            $filename,
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );
    }
}
