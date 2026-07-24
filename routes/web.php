<?php

use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\PanelController;
use App\Modules\AI\Http\Controllers\AiAssistantController;
use App\Modules\Billing\Http\Controllers\DgiiReportController;
use App\Modules\Billing\Http\Controllers\InvoiceController;
use App\Modules\Billing\Http\Controllers\PartsCounterController;
use App\Modules\Core\Http\Controllers\CompanyAdminController;
use App\Modules\Core\Http\Controllers\CompanySwitchController;
use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\PlanController;
use App\Modules\Core\Http\Controllers\SuspensionController;
use App\Modules\Core\Http\Controllers\UserController;
use App\Modules\CRM\Http\Controllers\CustomerController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\EmployeePortalController;
use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Loans\Http\Controllers\LoanController;
use App\Modules\POS\Http\Controllers\PosController;
use App\Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use App\Modules\Sales\Http\Controllers\SaleController;
use App\Modules\WhatsApp\Http\Controllers\EvolutionWebhookController;
use App\Modules\WhatsApp\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

/*
 * Cada ruta declara el permiso que exige (middleware `can:`). Ocultar un botón en la interfaz no
 * es seguridad: quien escriba la URL a mano llegaría igual. La autorización se aplica aquí, en el
 * borde, y la vista solo refleja lo que el usuario ya puede hacer.
 *
 * El super administrador atraviesa todas estas comprobaciones (Gate::before en CoreServiceProvider).
 */

Route::redirect('/', '/dashboard');

Route::middleware(['auth'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->middleware(['can:dashboard.view', 'subscription'])->name('dashboard');

    // Portal del empleado: es su propia ficha, no requiere permisos de módulo.
    Route::get('/portal/perfil', EmployeePortalController::class)->name('portal.employee');

    // Cuenta y suscripción de la empresa. Solo el Propietario (company.manage): la facturación y el
    // plan son asunto del dueño, no del administrador. Sin el middleware de suscripción: debe seguir
    // siendo accesible aunque la suscripción esté vencida, para poder ver el estado y regularizar.
    Route::get('/panel/cuenta', [PanelController::class, 'account'])
        ->middleware('can:company.manage')->name('panel.account');

    // Aviso de cuenta suspendida (accesible sin suscripción/empresa activa, por eso queda fuera
    // del middleware que bloquea).
    Route::get('/cuenta-suspendida', SuspensionController::class)->name('panel.suspended');

    // Conmutador de empresa (solo super admin; el propio controlador lo verifica).
    Route::post('/panel/empresa/{company}/activar', [CompanySwitchController::class, 'switch'])->name('panel.company.switch');

    // Panel de administración (una pantalla por módulo): cada una exige el permiso de lectura y
    // que la suscripción esté al día.
    Route::controller(PanelController::class)->prefix('panel')->name('panel.')->middleware('subscription')->group(function (): void {
        Route::get('/pos', 'pos')->middleware(['can:pos.operate', 'module:pos'])->name('pos');
        Route::get('/inventario', 'products')->middleware(['can:products.view', 'module:inventory'])->name('products');
        // Entrada de mercancía: dar existencia es un permiso distinto de consultarla.
        Route::get('/inventario/entradas', 'stockEntry')->middleware(['can:stock.adjust', 'module:inventory'])->name('stock.entry');
        Route::get('/ventas', 'sales')->middleware(['can:sales.view', 'module:sales'])->name('sales');
        Route::get('/compras', 'purchases')->middleware(['can:purchases.view', 'module:purchasing'])->name('purchases');
        Route::get('/crm', 'customers')->middleware(['can:customers.view', 'module:crm'])->name('customers');
        Route::get('/whatsapp', 'whatsapp')->middleware(['can:whatsapp.view', 'module:whatsapp'])->name('whatsapp');
        Route::get('/facturas', 'invoices')->middleware(['can:invoices.view', 'module:billing'])->name('invoices');
        Route::get('/finanzas', 'finance')->middleware(['can:finance.view', 'module:finance'])->name('finance');
        Route::get('/prestamos', 'loans')->middleware(['can:loans.view', 'module:loans'])->name('loans');
        Route::get('/prestamos/{loan}', 'loanShow')->middleware(['can:loans.view', 'module:loans'])->name('loans.show');
        Route::get('/entregas', 'deliveries')->middleware(['can:delivery.view', 'module:delivery'])->name('deliveries');
        Route::get('/rrhh', 'employees')->middleware(['can:hr.view', 'module:hr'])->name('employees');
        Route::get('/ia', 'ai')->middleware(['can:ai.assistant.use', 'module:ai'])->name('ai');
        Route::get('/reportes', 'reports')->middleware(['can:reports.view', 'module:reports'])->name('reports');
        Route::get('/usuarios', 'users')->middleware('can:users.manage')->name('users');
    });

    // Administración de usuarios y sus roles (dentro de la empresa activa).
    Route::middleware(['can:users.manage', 'subscription'])->group(function (): void {
        Route::post('/panel/usuarios', [UserController::class, 'store'])->name('panel.users.store');
        Route::put('/panel/usuarios/{user}', [UserController::class, 'update'])->name('panel.users.update');
        Route::post('/panel/usuarios/{user}/estado', [UserController::class, 'toggle'])->name('panel.users.toggle');
    });

    // Panel del operador de la plataforma (super admin). «platform.manage» no lo tiene ningún
    // rol de empresa: solo el super admin lo atraviesa vía Gate::before.
    Route::middleware('can:platform.manage')->group(function (): void {
        Route::get('/plataforma/empresas', [CompanyAdminController::class, 'index'])->name('platform.companies');
        Route::post('/plataforma/empresas', [CompanyAdminController::class, 'store'])->name('platform.companies.store');
        Route::put('/plataforma/empresas/{company}/modulos', [CompanyAdminController::class, 'updateModules'])->name('platform.companies.modules');
        Route::put('/plataforma/empresas/{company}/pos', [CompanyAdminController::class, 'updatePosProfile'])->name('platform.companies.pos');
        Route::post('/plataforma/empresas/{company}/estado', [CompanyAdminController::class, 'toggleActive'])->name('platform.companies.toggle');

        // Suscripción de cada empresa (cobro manual).
        Route::post('/plataforma/empresas/{company}/suscribir', [CompanyAdminController::class, 'subscribe'])->name('platform.companies.subscribe');
        Route::post('/plataforma/empresas/{company}/pago', [CompanyAdminController::class, 'registerPayment'])->name('platform.companies.payment');
        Route::post('/plataforma/empresas/{company}/suspender', [CompanyAdminController::class, 'suspendSubscription'])->name('platform.companies.suspend');

        // Planes de suscripción.
        Route::get('/plataforma/planes', [PlanController::class, 'index'])->name('platform.plans');
        Route::post('/plataforma/planes', [PlanController::class, 'store'])->name('platform.plans.store');
        Route::put('/plataforma/planes/{plan}', [PlanController::class, 'update'])->name('platform.plans.update');
        Route::delete('/plataforma/planes/{plan}', [PlanController::class, 'destroy'])->name('platform.plans.destroy');
    });

    // Recibo imprimible de una venta.
    Route::get('/panel/ventas/{sale}/recibo', [SaleController::class, 'receipt'])
        ->middleware(['can:sales.view', 'module:sales'])->name('panel.sales.receipt');

    // Recibo en PDF de 80mm (ver en el navegador o ?mode=descargar). Para imprimir, enviar o archivar.
    Route::get('/panel/ventas/{sale}/recibo/pdf/{mode?}', [SaleController::class, 'receiptPdf'])
        ->middleware(['can:sales.view', 'module:sales'])->name('panel.sales.receipt.pdf');

    // Exportaciones a CSV: exigen el mismo permiso (y módulo) que ver los datos que exportan.
    Route::controller(ExportController::class)->prefix('panel/exportar')->name('panel.export.')->group(function (): void {
        Route::get('/productos', 'products')->middleware(['can:products.view', 'module:inventory'])->name('products');
        Route::get('/ventas', 'sales')->middleware(['can:sales.view', 'module:sales'])->name('sales');
        Route::get('/clientes', 'customers')->middleware(['can:customers.view', 'module:crm'])->name('customers');
        Route::get('/facturas', 'invoices')->middleware(['can:invoices.view', 'module:billing'])->name('invoices');
        Route::get('/reporte-ventas', 'salesReport')->middleware(['can:reports.view', 'module:reports'])->name('sales-report');
    });

    // Punto de Venta: abrir y cerrar caja son permisos distintos de cobrar.
    Route::controller(PosController::class)->prefix('panel/pos')->middleware('module:pos')->name('panel.pos.')->group(function (): void {
        // Búsqueda del producto escaneado. Exige el mismo permiso que cobrar: quien no puede
        // operar el POS tampoco tiene por qué consultar precios desde él.
        Route::get('/buscar', 'lookup')->middleware('can:pos.operate')->name('lookup');
        // Búsqueda difusa por SKU/nombre para el mostrador (reemplaza cargar todo el catálogo).
        Route::get('/buscar-productos', 'search')->middleware('can:pos.operate')->name('search');
        Route::post('/abrir-caja', 'openSession')->middleware('can:cash.open')->name('open');
        Route::post('/cobrar', 'checkout')->middleware('can:pos.operate')->name('checkout');
        Route::post('/cerrar-caja', 'closeSession')->middleware('can:cash.close')->name('close');
    });

    // Altas, ediciones y bajas desde el panel. El route model binding resuelve el registro ya
    // aislado por la empresa activa (un id de otra empresa devuelve 404).
    // Entrada de mercancía al almacén. Buscar el código exige solo ver el catálogo; registrar la
    // entrada exige «stock.adjust», que el rol de cajero no tiene: quien puede inflar existencias
    // podría tapar un faltante, así que operar la caja y dar entrada son permisos separados.
    Route::middleware('module:inventory')->group(function (): void {
        Route::get('/panel/inventario/buscar', [StockController::class, 'lookup'])
            ->middleware('can:products.view')->name('panel.products.lookup');
        Route::post('/panel/inventario/entradas', [StockController::class, 'store'])
            ->middleware('can:stock.adjust')->name('panel.stock.store');
    });

    Route::middleware(['can:products.manage', 'module:inventory'])->group(function (): void {
        Route::post('/panel/inventario', [ProductController::class, 'store'])->name('panel.products.store');
        Route::put('/panel/inventario/{product}', [ProductController::class, 'update'])->name('panel.products.update');
        Route::delete('/panel/inventario/{product}', [ProductController::class, 'destroy'])->name('panel.products.destroy');
    });

    Route::middleware(['can:customers.manage', 'module:crm'])->group(function (): void {
        Route::post('/panel/crm', [CustomerController::class, 'store'])->name('panel.customers.store');
        Route::put('/panel/crm/{customer}', [CustomerController::class, 'update'])->name('panel.customers.update');
        Route::delete('/panel/crm/{customer}', [CustomerController::class, 'destroy'])->name('panel.customers.destroy');

        // Enviar al cliente el enlace de su portal por WhatsApp. Exige también el módulo de
        // WhatsApp: es el canal por el que se entrega.
        Route::post('/panel/crm/{customer}/portal', [CustomerController::class, 'sendPortalLink'])
            ->middleware('module:whatsapp')->name('panel.customers.portal');
    });

    // Órdenes de compra. Van bajo «compras/ordenes» porque «/panel/compras» ya lo ocupan los
    // proveedores; y solo por POST: un PUT aquí chocaría con «PUT /panel/compras/{supplier}», donde
    // «ordenes» encajaría como si fuera el id de un proveedor.
    //
    // Crear la orden y recibir la mercancía son permisos distintos: comprometer dinero con un
    // proveedor no es lo mismo que dar por buena la mercancía que llegó.
    Route::middleware('module:purchasing')->group(function (): void {
        Route::post('/panel/compras/ordenes', [PurchaseOrderController::class, 'store'])
            ->middleware('can:purchases.manage')->name('panel.purchase-orders.store');
        Route::post('/panel/compras/ordenes/{order}/recibir', [PurchaseOrderController::class, 'receive'])
            ->middleware('can:purchases.receive')->name('panel.purchase-orders.receive');
    });

    Route::middleware(['can:suppliers.manage', 'module:purchasing'])->group(function (): void {
        Route::post('/panel/compras', [SupplierController::class, 'store'])->name('panel.suppliers.store');
        Route::put('/panel/compras/{supplier}', [SupplierController::class, 'update'])->name('panel.suppliers.update');
        Route::delete('/panel/compras/{supplier}', [SupplierController::class, 'destroy'])->name('panel.suppliers.destroy');
    });

    // Préstamos: crear el préstamo (desembolsa capital), registrar abonos, ajustar la mora de una
    // cuota y anular. Todas mutan dinero/saldo, por eso exigen loans.manage.
    Route::middleware(['can:loans.manage', 'module:loans'])->group(function (): void {
        Route::post('/panel/prestamos', [LoanController::class, 'store'])->name('panel.loans.store');
        Route::post('/panel/prestamos/{loan}/pagos', [LoanController::class, 'payment'])->name('panel.loans.payments.store');
        Route::post('/panel/prestamos/{loan}/cuotas/{installment}/mora', [LoanController::class, 'setFee'])->name('panel.loans.installments.fee');
        Route::post('/panel/prestamos/{loan}/anular', [LoanController::class, 'cancel'])->name('panel.loans.cancel');
    });

    Route::middleware(['can:hr.manage', 'module:hr'])->group(function (): void {
        Route::post('/panel/rrhh', [EmployeeController::class, 'store'])->name('panel.employees.store');
        Route::put('/panel/rrhh/{employee}', [EmployeeController::class, 'update'])->name('panel.employees.update');
        Route::delete('/panel/rrhh/{employee}', [EmployeeController::class, 'destroy'])->name('panel.employees.destroy');
    });

    // Facturación fiscal (DGII). Emitir es una acción de caja; anular un NCF y administrar las
    // secuencias autorizadas, no: llevan permisos propios que el rol «staff» no tiene.
    Route::middleware('module:billing')->group(function (): void {
        // Mostrador de repuestos: facturación directa (busca pieza → ticket → NCF + descuento stock).
        Route::get('/panel/mostrador', [PartsCounterController::class, 'index'])
            ->middleware('can:invoices.issue')->name('panel.parts');
        Route::get('/panel/mostrador/buscar', [PartsCounterController::class, 'search'])
            ->middleware('can:invoices.issue')->name('panel.parts.search');
        Route::post('/panel/mostrador/facturar', [PartsCounterController::class, 'invoice'])
            ->middleware('can:invoices.issue')->name('panel.parts.invoice');

        Route::post('/panel/facturas/emitir', [InvoiceController::class, 'issue'])
            ->middleware('can:invoices.issue')->name('panel.invoices.issue');
        Route::post('/panel/facturas/{invoice}/anular', [InvoiceController::class, 'cancel'])
            ->middleware('can:invoices.cancel')->name('panel.invoices.cancel');
        Route::post('/panel/facturas/secuencias', [InvoiceController::class, 'storeSequence'])
            ->middleware('can:fiscal_sequences.manage')->name('panel.sequences.store');
        Route::get('/panel/facturas/dgii/607', [DgiiReportController::class, 'sales607'])
            ->middleware('can:invoices.view')->name('panel.dgii.607');
        Route::get('/panel/facturas/dgii/608', [DgiiReportController::class, 'cancelled608'])
            ->middleware('can:invoices.view')->name('panel.dgii.608');
    });

    // Bandeja de WhatsApp. Vincular la línea afecta a toda la empresa: permiso aparte.
    Route::middleware('module:whatsapp')->group(function (): void {
        Route::get('/panel/whatsapp/bandeja', [WhatsAppController::class, 'poll'])
            ->middleware('can:whatsapp.view')->name('panel.whatsapp.poll');
        Route::post('/panel/whatsapp/enviar', [WhatsAppController::class, 'send'])
            ->middleware('can:whatsapp.send')->name('panel.whatsapp.send');
        Route::post('/panel/whatsapp/conectar', [WhatsAppController::class, 'connect'])
            ->middleware('can:whatsapp.connect')->name('panel.whatsapp.connect');
    });

    // Asistente de IA (RAG): consultar es de uso diario; alimentar la base de conocimiento, no.
    Route::middleware('module:ai')->group(function (): void {
        Route::post('/panel/ia/preguntar', [AiAssistantController::class, 'ask'])
            ->middleware('can:ai.assistant.use')->name('panel.ai.ask');
        Route::post('/panel/ia/documentos', [AiAssistantController::class, 'store'])
            ->middleware('can:ai.documents.manage')->name('panel.ai.documents.store');
        Route::delete('/panel/ia/documentos/{document}', [AiAssistantController::class, 'destroy'])
            ->middleware('can:ai.documents.manage')->name('panel.ai.documents.destroy');
    });
});

// Portal del cliente (sin sesión: el cliente no es usuario del sistema). La autenticación es la
// firma de la URL: `signed` verifica el HMAC y la caducidad, así que un id manipulado no llega al
// controlador. La empresa se deriva del cliente del enlace, nunca de la sesión.
Route::get('/portal/cliente/{customer}', CustomerPortalController::class)
    ->middleware('signed')->name('portal.customer');

// Webhook entrante de Evolution API (sin sesión; protegido por secreto compartido).
Route::post('/webhooks/evolution', EvolutionWebhookController::class)->name('webhooks.evolution');
