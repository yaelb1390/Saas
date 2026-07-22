<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Billing\Models\Invoice;
use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de las listas del panel a CSV (compatible con Excel). Respeta el aislamiento por
 * empresa y los filtros de búsqueda/fechas activos.
 */
final class ExportController extends Controller
{
    public function products(): StreamedResponse
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

        return $this->csv('productos.csv',
            ['SKU', 'Nombre', 'Categoría', 'Unidad', 'Costo', 'Precio', 'Stock', 'Estado'], $rows);
    }

    public function sales(): StreamedResponse
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

        return $this->csv('ventas.csv',
            ['Código', 'Cliente', 'Líneas', 'Subtotal', 'ITBIS', 'Total', 'Pago', 'Estado', 'Fecha'], $rows);
    }

    public function customers(): StreamedResponse
    {
        $q = request('q');
        $rows = Customer::query()->withCount('opportunities')
            ->when($q, fn ($query, $q) => $query->where(
                fn ($s) => $s->whereLike('name', "%{$q}%")->orWhereLike('phone', "%{$q}%")->orWhereLike('email', "%{$q}%")
            ))
            ->orderBy('name')->get()
            ->map(fn (Customer $c) => [$c->name, $c->phone, $c->email, $c->tax_id, $c->opportunities_count]);

        return $this->csv('clientes.csv',
            ['Nombre', 'Teléfono', 'Correo', 'RNC/Cédula', 'Oportunidades'], $rows);
    }

    public function invoices(): StreamedResponse
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

        return $this->csv('facturas.csv',
            ['NCF', 'Tipo', 'Cliente', 'Subtotal', 'ITBIS', 'Total', 'Estado', 'Emitida'], $rows);
    }

    public function salesReport(ReportService $reports): StreamedResponse
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

        return $this->csv('reporte-ventas.csv', ['Fecha', 'Total vendido'], $rows);
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
}
