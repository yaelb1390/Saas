<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Billing\Models\Invoice;
use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de las listas del panel. Cada lista se puede bajar en CSV (compatible con Excel) o en
 * XLSX nativo, según el parámetro ?format=xlsx. Respeta el aislamiento por empresa y los filtros de
 * búsqueda/fechas activos.
 */
final class ExportController extends Controller
{
    public function products(): Response
    {
        $q = request('q');
        $rows = Product::query()->with(['category', 'stock'])
            ->when($q, fn ($query, $q) => $query->where(
                fn ($s) => $s->whereLike('sku', "%{$q}%")->orWhereLike('name', "%{$q}%")
            ))
            ->orderBy('name')->get()
            ->map(fn (Product $p) => [
                $p->sku, $p->name, $p->category?->name, $p->unit,
                (float) $p->cost, (float) $p->price, (float) $p->stock->sum('quantity'),
                $p->is_active ? 'Activo' : 'Inactivo',
            ]);

        return $this->download('productos',
            ['SKU', 'Nombre', 'Categoría', 'Unidad', 'Costo', 'Precio', 'Stock', 'Estado'], $rows);
    }

    public function sales(): Response
    {
        $q = request('q');
        $rows = Sale::query()->withCount('items')
            ->when($q, fn ($query, $q) => $query->where(
                fn ($s) => $s->whereLike('code', "%{$q}%")->orWhereLike('customer_name', "%{$q}%")
            ))
            ->latest()->get()
            ->map(fn (Sale $s) => [
                $s->code, $s->customer_name, $s->items_count,
                (float) $s->subtotal, (float) $s->tax, (float) $s->total,
                $s->payment_method, $s->status->label(),
                $s->created_at?->format('Y-m-d H:i'),
            ]);

        return $this->download('ventas',
            ['Código', 'Cliente', 'Líneas', 'Subtotal', 'ITBIS', 'Total', 'Pago', 'Estado', 'Fecha'], $rows);
    }

    public function customers(): Response
    {
        $q = request('q');
        $rows = Customer::query()->withCount('opportunities')
            ->when($q, fn ($query, $q) => $query->where(
                fn ($s) => $s->whereLike('name', "%{$q}%")->orWhereLike('phone', "%{$q}%")->orWhereLike('email', "%{$q}%")
            ))
            ->orderBy('name')->get()
            ->map(fn (Customer $c) => [$c->name, $c->cedula, $c->phone, $c->email, $c->tax_id, $c->opportunities_count]);

        return $this->download('clientes',
            ['Nombre', 'Cédula', 'Teléfono', 'Correo', 'RNC', 'Oportunidades'], $rows);
    }

    public function invoices(): Response
    {
        $q = request('q');
        $rows = Invoice::query()
            ->when($q, fn ($query, $q) => $query->where(
                fn ($s) => $s->whereLike('ncf', "%{$q}%")->orWhereLike('customer_name', "%{$q}%")
            ))
            ->latest()->get()
            ->map(fn (Invoice $i) => [
                $i->ncf, $i->type->value, $i->customer_name,
                (float) $i->subtotal, (float) $i->tax, (float) $i->total,
                $i->status, $i->issued_at?->format('Y-m-d H:i'),
            ]);

        return $this->download('facturas',
            ['NCF', 'Tipo', 'Cliente', 'Subtotal', 'ITBIS', 'Total', 'Estado', 'Emitida'], $rows);
    }

    public function salesReport(ReportService $reports): Response
    {
        $from = request()->filled('from')
            ? rescue(fn () => Carbon::parse((string) request('from')), Carbon::now()->subDays(29), report: false)
            : Carbon::now()->subDays(29);
        $to = request()->filled('to')
            ? rescue(fn () => Carbon::parse((string) request('to')), Carbon::now(), report: false)
            : Carbon::now();

        $report = $reports->salesReport($from, $to);
        $rows = [];
        foreach ($report['days'] as $date => $total) {
            $rows[] = [$date, $total];
        }

        return $this->download('reporte-ventas', ['Fecha', 'Total vendido'], $rows);
    }

    /**
     * Elige el formato de descarga según ?format=xlsx (por defecto CSV).
     *
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $headers
     */
    private function download(string $base, array $headers, iterable $rows): Response
    {
        return request('format') === 'xlsx'
            ? $this->xlsx("{$base}.xlsx", $headers, $rows)
            : $this->csv("{$base}.csv", $headers, $rows);
    }

    /**
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $headers
     */
    private function csv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para que Excel respete los acentos
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * XLSX nativo con openspout. Se escribe a un temporal (/tmp escribible en serverless) y se envía
     * como descarga; el archivo se borra tras enviarse. Los números salen como celdas numéricas.
     *
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $headers
     */
    private function xlsx(string $filename, array $headers, iterable $rows): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(
                fn ($v) => $v ?? '', // openspout no acepta null en una celda
                array_values((array) $row),
            )));
        }
        $writer->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
