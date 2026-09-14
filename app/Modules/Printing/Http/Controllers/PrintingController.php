<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Controllers;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Printing\DTOs\SavePrinterData;
use App\Modules\Printing\DTOs\SaveTemplateData;
use App\Modules\Printing\Enums\PrinterStatus;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Http\Requests\SavePrinterRequest;
use App\Modules\Printing\Http\Requests\SaveTemplateRequest;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintTemplate;
use App\Modules\Printing\Services\DocumentRenderer;
use App\Modules\Printing\Services\ModulePrinterResolver;
use App\Modules\Printing\Services\PrinterRegistry;
use App\Modules\Printing\Services\PrintJobLogger;
use App\Modules\Printing\Services\TemplateService;
use App\Modules\Printing\Support\DocumentType;
use App\Modules\Printing\Support\PaperSize;
use App\Modules\Printing\Support\PrintableModules;
use App\Modules\Printing\Support\SaleTicketAdapter;
use App\Modules\Printing\Support\SampleDataFactory;
use App\Modules\Sales\Models\Sale;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * El Centro de Impresión: registrar impresoras, diseñar plantillas, elegir cuál usa cada módulo y
 * consultar qué se imprimió. Delgado a propósito: cada decisión de negocio vive en su servicio
 * (`PrinterRegistry`, `TemplateService`, `ModulePrinterResolver`, `DocumentRenderer`,
 * `PrintJobLogger`); aquí solo se traduce la petición HTTP a esas llamadas.
 */
final class PrintingController extends Controller
{
    /**
     * La pantalla entera, con sus pestañas: buscar impresoras, Bluetooth, configuración, plantillas,
     * impresora por módulo e historial. Una sola vista con pestañas —como el resto del panel— y no
     * seis rutas, porque son aspectos del mismo centro, no seis destinos distintos del menú.
     */
    public function index(Request $request, CurrentCompany $currentCompany, ModulePrinterResolver $resolver): View
    {
        $company = $currentCompany->model();
        $usuario = $request->user();

        $printers = Printer::query()->with('createdBy')->orderByDesc('id')->get();
        $templates = PrintTemplate::query()->orderBy('document_type')->orderByDesc('is_default')->get();

        $historial = PrintJob::query()
            ->with(['user', 'printer'])
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->string('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->string('hasta')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('printer_id'), fn ($q) => $q->where('printer_id', $request->integer('printer_id')))
            ->when($request->filled('document_type'), fn ($q) => $q->where('document_type', $request->string('document_type')))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('panel.printing.index', [
            'printers' => $printers,
            'templates' => $templates,
            'historial' => $historial,
            'modulesMap' => $company ? $resolver->modulesMap($company) : [],
            'printableModules' => PrintableModules::options(),
            'paperSizes' => PaperSize::options(),
            'documentTypeGroups' => DocumentType::grouped(),
            'documentTypeCategories' => DocumentType::categories(),
            'miPredeterminada' => $usuario && $company ? app(PrinterRegistry::class)->predeterminadaDe($usuario, $company->id) : null,
            'esPrimeraVez' => $printers->isEmpty(),
            // Para los filtros del historial: quién ha impreso algo en esta empresa.
            'usuariosConHistorial' => PrintJob::query()->with('user:id,name')->get()->pluck('user')->filter()->unique('id')->sortBy('name')->values(),
        ]);
    }

    // ------------------------------------------------------------------ Impresoras

    public function storePrinter(SavePrinterRequest $request, PrinterRegistry $registro): RedirectResponse
    {
        $printer = $registro->crear(SavePrinterData::fromArray($request->validated()), $request->user());

        return back()->with('panel_ok', "Impresora «{$printer->name}» registrada.");
    }

    public function updatePrinter(SavePrinterRequest $request, Printer $printer, PrinterRegistry $registro): RedirectResponse
    {
        $registro->actualizar($printer, SavePrinterData::fromArray($request->validated()));

        return back()->with('panel_ok', "Impresora «{$printer->name}» actualizada.");
    }

    public function destroyPrinter(Printer $printer, PrinterRegistry $registro): RedirectResponse
    {
        $nombre = $printer->name;
        $registro->borrar($printer);

        return back()->with('panel_ok', "Impresora «{$nombre}» retirada.");
    }

    /**
     * Cambia el estado que se ENSEÑA de una impresora —conectada, desconectada, error—. Lo dispara
     * el navegador tras intentar de verdad conectar (Bluetooth) o al pulsar «Desconectar»: el
     * servidor nunca sondea el hardware por su cuenta.
     */
    public function setPrinterStatus(Request $request, Printer $printer, PrinterRegistry $registro): RedirectResponse
    {
        $datos = $request->validate([
            'status' => ['required', Rule::enum(PrinterStatus::class)],
        ]);

        $registro->marcarEstado($printer, PrinterStatus::from($datos['status']));

        return back()->with('panel_ok', "«{$printer->name}»: ".PrinterStatus::from($datos['status'])->label().'.');
    }

    public function setDefaultPrinter(Request $request, PrinterRegistry $registro, CurrentCompany $currentCompany): RedirectResponse
    {
        $datos = $request->validate([
            'printer_id' => ['nullable', 'integer', Rule::exists('printers', 'id')->where('company_id', $currentCompany->id())],
        ]);

        $printer = isset($datos['printer_id']) ? Printer::query()->find($datos['printer_id']) : null;
        $registro->marcarPredeterminada($request->user(), $currentCompany->id(), $printer);

        return back()->with('panel_ok', $printer ? "«{$printer->name}» es ahora tu impresora predeterminada." : 'Se quitó tu impresora predeterminada.');
    }

    // ------------------------------------------------------------------ Plantillas

    public function storeTemplate(SaveTemplateRequest $request, TemplateService $plantillas): RedirectResponse
    {
        $template = $plantillas->guardar(null, SaveTemplateData::fromArray($request->validated()));

        return back()->with('panel_ok', "Plantilla «{$template->name}» guardada.");
    }

    public function updateTemplate(SaveTemplateRequest $request, PrintTemplate $template, TemplateService $plantillas): RedirectResponse
    {
        $plantillas->guardar($template, SaveTemplateData::fromArray($request->validated()));

        return back()->with('panel_ok', "Plantilla «{$template->name}» actualizada.");
    }

    public function destroyTemplate(PrintTemplate $template, TemplateService $plantillas): RedirectResponse
    {
        $nombre = $template->name;
        $plantillas->borrar($template);

        return back()->with('panel_ok', "Plantilla «{$nombre}» borrada.");
    }

    // ------------------------------------------------------------------ Impresora por módulo

    public function assignModule(Request $request, CurrentCompany $currentCompany, ModulePrinterResolver $resolver): RedirectResponse
    {
        $datos = $request->validate([
            'module' => ['required', Rule::in(PrintableModules::keys())],
            'printer_id' => ['nullable', 'integer', Rule::exists('printers', 'id')->where('company_id', $currentCompany->id())],
        ]);

        $resolver->asignar($currentCompany->model(), $datos['module'], $datos['printer_id'] ?? null);

        return back()->with('panel_ok', PrintableModules::options()[$datos['module']].': impresora asignada.');
    }

    // ------------------------------------------------------------------ Render + historial

    /**
     * El documento listo para imprimir: el HTML (para el diálogo del navegador o la vista previa) y
     * los comandos ESC/POS en base64 (para el camino Bluetooth).
     *
     * Con `sale_id`, usa la venta real (`SaleTicketAdapter`) — es la integración de referencia. Sin
     * eso, o para cualquier otro tipo de documento, usa datos de muestra: es lo que deja probar y
     * diseñar CUALQUIER plantilla —incluidas las 28 que ningún módulo conecta todavía— sin esperar
     * a que ese módulo exista.
     */
    public function render(Request $request, CurrentCompany $currentCompany, TemplateService $plantillas, DocumentRenderer $renderer, ModulePrinterResolver $resolver): JsonResponse
    {
        $datos = $request->validate([
            'document_type' => ['required', Rule::in(DocumentType::keys())],
            'template_id' => ['nullable', 'integer', Rule::exists('print_templates', 'id')->where('company_id', $currentCompany->id())],
            'sale_id' => ['nullable', 'integer', Rule::exists('sales', 'id')->where('company_id', $currentCompany->id())],
            // El propio diseño en edición, sin guardar todavía: así la vista previa refleja lo que
            // se está tocando en el editor, no solo la última versión guardada.
            'layout' => ['nullable', 'array'],
            'paper_size' => ['nullable', Rule::in(PaperSize::keys())],
            // Con qué módulo se imprime (Ventas, Facturación…): es lo que deja resolver la impresora
            // asignada. Sin esto —la vista previa del editor, por ejemplo— no se resuelve ninguna.
            'module' => ['nullable', Rule::in(PrintableModules::keys())],
        ]);

        $template = isset($datos['template_id'])
            ? PrintTemplate::query()->find($datos['template_id'])
            : $plantillas->predeterminadaPara($datos['document_type']);

        // Sin plantilla guardada todavía: se arma una "al vuelo" con el diseño de fábrica —o el que
        // el editor tiene sin guardar—, para que la vista previa nunca dependa de haber pulsado
        // Guardar primero.
        if ($template === null) {
            $template = new PrintTemplate([
                'document_type' => $datos['document_type'],
                'paper_size' => $datos['paper_size'] ?? DocumentType::defaultPaperSize($datos['document_type']),
                'layout' => $plantillas->layoutPorDefecto($datos['document_type']),
            ]);
        } elseif (isset($datos['layout'])) {
            $template->layout = array_replace($template->layout, $datos['layout']);
        }
        if (isset($datos['paper_size'])) {
            $template->paper_size = $datos['paper_size'];
        }

        $data = ($datos['document_type'] === 'sale_ticket' && isset($datos['sale_id']))
            ? SaleTicketAdapter::desde(
                Sale::query()->with(['items.product', 'items.employee', 'items.options'])->findOrFail($datos['sale_id']),
                Invoice::query()->where('sale_id', $datos['sale_id'])->first(),
            )
            : SampleDataFactory::paraTipo($datos['document_type']);

        $company = $currentCompany->model();
        $html = $renderer->renderHtml($template, $company, $data);
        $escpos = $renderer->renderEscPos($template, $company, $data);

        // La impresora que le toca a este módulo (asignada, o la predeterminada de quien imprime).
        // Sin `module` —la vista previa del editor de plantillas, por ejemplo— no se resuelve
        // ninguna: ahí no se imprime de verdad, solo se mira.
        $printer = isset($datos['module']) ? $resolver->resolver($datos['module'], $request->user(), $company) : null;

        return response()->json([
            'html' => $html['html'],
            'escpos_base64' => $escpos,
            'ancho_mm' => $html['ancho_mm'],
            'es_rollo' => $html['es_rollo'],
            'template_id' => $template->exists ? $template->id : null,
            'reference' => $data->reference,
            'printer' => $printer ? [
                'id' => $printer->id,
                'name' => $printer->name,
                'connection_type' => $printer->connection_type->value,
                'bt_device_id' => $printer->bt_device_id,
            ] : null,
        ]);
    }

    /**
     * El navegador avisa aquí después de intentar imprimir de verdad —salga bien, falle o se
     * cancele—. Es lo que llena el historial; el servidor nunca sabe por su cuenta si el papel
     * salió, porque no es quien habla con la impresora.
     */
    public function logJob(Request $request, PrintJobLogger $logger): JsonResponse
    {
        $datos = $request->validate([
            'document_type' => ['required', Rule::in(DocumentType::keys())],
            'printer_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'integer'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:20'],
            'paper_size' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(PrintJobStatus::class)],
            'error_message' => ['nullable', 'string', 'max:255'],
        ]);

        // Los ids llegan sueltos y no por route-model-binding —el aviso puede llegar tras haber
        // borrado la impresora entre medias—, así que se buscan a mano y se ignoran si ya no existen
        // (o son de otra empresa: el scope de compañía los filtra solo).
        $printer = isset($datos['printer_id']) ? Printer::query()->find($datos['printer_id']) : null;
        $template = isset($datos['template_id']) ? PrintTemplate::query()->find($datos['template_id']) : null;

        $job = $logger->registrar(
            usuario: $request->user(),
            documentType: $datos['document_type'],
            estado: PrintJobStatus::from($datos['status']),
            printer: $printer,
            template: $template,
            copias: $datos['copies'] ?? 1,
            paperSize: $datos['paper_size'] ?? null,
            errorMessage: $datos['error_message'] ?? null,
        );

        return response()->json(['id' => $job->id], 201);
    }
}
