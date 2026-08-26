<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Http\Controllers;

use App\Modules\Core\Support\CompanyLogoStore;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Quotes\Services\QuoteConverter;
use App\Modules\Quotes\Services\QuoteDelivery;
use App\Modules\Quotes\Services\QuoteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Cotizaciones desde el panel: crearlas, mandarlas y cobrarlas.
 */
final class QuoteController extends Controller
{
    public function index(QuoteDelivery $delivery): View
    {
        /*
         * Si la tabla todavía no existe, la pantalla se pinta vacía en vez de dar un 500.
         *
         * Aquí las migraciones se aplican a mano y el despliegue no las corre: entre que sale el
         * código y alguien migra pasan horas, y en ese hueco esta consulta se encontraría una base
         * vieja. Ya tumbó la pantalla de Redes sociales en producción una vez.
         */
        $cotizaciones = DbTable::existe('quotes')
            ? Quote::query()->with('items')->latest()->paginate(20)
            : null;

        return view('panel.quotes', [
            'cotizaciones' => $cotizaciones,
            'hayTabla' => $cotizaciones !== null,
            // Para saber si ofrecer «mandar el PDF» o solo el enlace.
            'puedeAdjuntar' => $delivery->puedeAdjuntar(),
            'delivery' => $delivery,
        ]);
    }

    public function create(): View
    {
        return view('panel.quote-form', [
            'clientes' => Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'productos' => Product::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'sku', 'price']),
            'validezPorOmision' => QuoteService::VALIDEZ_POR_OMISION,
        ]);
    }

    public function store(Request $request, QuoteService $quotes): RedirectResponse
    {
        $companyId = app(CurrentCompany::class)->id();

        $datos = $request->validate([
            // Las reglas «exists» consultan la base sin pasar por el scope de empresa: hay que
            // acotarlas a mano o aceptarían el id de un cliente ajeno.
            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'valid_until' => ['nullable', 'date'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => [
                'nullable', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            // Sin producto hace falta el texto: una línea sin nombre ni producto no dice nada en el
            // papel que recibe el cliente.
            'lines.*.description' => ['required_without:lines.*.product_id', 'nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ], [
            'customer_name.required_without' => 'Dinos a quién se cotiza: elige un cliente o escribe su nombre.',
            'lines.required' => 'Una cotización necesita al menos una línea.',
        ]);

        $quote = $quotes->crear($datos['lines'], $datos);

        return redirect()
            ->route('panel.quotes.show', $quote)
            ->with('panel_ok', "Cotización {$quote->code} creada.");
    }

    public function show(Quote $quote, QuoteDelivery $delivery, QuoteConverter $converter): View
    {
        return view('panel.quote-show', [
            'quote' => $quote->load('items.product', 'customer', 'sale'),
            'enlace' => $delivery->enlace($quote),
            'enlaceWa' => $delivery->enlaceWa($quote),
            'puedeAdjuntar' => $delivery->puedeAdjuntar(),
            // Lo que hay que mirar ANTES de cobrar: precios que ya no coinciden, líneas sin producto.
            'diferencias' => $converter->diferencias($quote),
        ]);
    }

    /** Manda la cotización por WhatsApp desde el sistema. */
    public function send(Quote $quote, QuoteDelivery $delivery, QuoteService $quotes): RedirectResponse
    {
        try {
            $comoFue = $delivery->enviar($quote);
        } catch (Throwable $e) {
            /*
             * Se dice el motivo tal cual y NO se marca como enviada.
             *
             * El caso más común no es una avería: es que por la vía oficial de WhatsApp no se puede
             * escribir a quien no ha escrito antes —regla de Meta—. Decir «enviada» ahí sería la
             * mentira más cara de esta pantalla: el vendedor se queda esperando una respuesta que
             * nunca va a llegar porque el mensaje no salió.
             */
            return back()->with('panel_error', $e->getMessage());
        }

        $quotes->marcarEnviada($quote);

        return back()->with('panel_ok', $comoFue);
    }

    public function status(Request $request, Quote $quote, QuoteService $quotes): RedirectResponse
    {
        $datos = $request->validate([
            'status' => ['required', Rule::enum(QuoteStatus::class)->only([
                QuoteStatus::Accepted, QuoteStatus::Rejected, QuoteStatus::Sent,
            ])],
        ]);

        try {
            $quotes->marcarEstado($quote, QuoteStatus::from($datos['status']));
        } catch (Throwable $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Cotización actualizada.');
    }

    /** La convierte en venta: descuenta existencias y mete el dinero en la caja. */
    public function convert(Request $request, Quote $quote, QuoteConverter $converter): RedirectResponse
    {
        $datos = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:30'],
            'paid' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $venta = $converter->convertir($quote, $datos);
        } catch (Throwable $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return redirect()
            ->route('panel.quotes.show', $quote)
            ->with('panel_ok', "Cotización cobrada. Se registró la venta {$venta->code}.");
    }

    /** El PDF desde el panel. El del cliente va por la ruta firmada, sin sesión. */
    public function pdf(Quote $quote, ?string $mode = null): Response
    {
        return self::construirPdf($quote, $mode);
    }

    /**
     * El PDF, en A4.
     *
     * A4 y no el rollo de 80 mm del recibo: esto no se imprime en una impresora térmica, se lee en
     * un teléfono, se reenvía y a veces se imprime en un folio para firmarlo. El alto es fijo porque
     * dompdf sí pagina bien un A4 —lo que no sabe es autoajustar un papel de alto libre, que es el
     * problema que tiene el recibo—.
     */
    public static function construirPdf(Quote $quote, ?string $mode = null): Response
    {
        // 'user' es el VENDEDOR, y va en la cabecera del papel. Se carga aquí y no se deja al azar
        // de una carga perezosa: con lazy loading estricto esto reventaría en producción y no en local.
        $quote->loadMissing('items', 'customer', 'user');

        $pdf = Pdf::loadView('quotes.pdf', [
            'quote' => $quote,
            'company' => $quote->company,
            'logo' => $quote->company?->hasLogo() ? CompanyLogoStore::dataUri($quote->company) : null,
        ])->setPaper('a4');

        $nombre = 'cotizacion-'.$quote->code.'.pdf';

        return $mode === 'descargar' ? $pdf->download($nombre) : $pdf->stream($nombre);
    }
}
