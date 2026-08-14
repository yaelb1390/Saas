<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\PanelController;
use App\Modules\AI\Http\Controllers\AiAssistantController;
use App\Modules\Billing\Http\Controllers\DgiiReportController;
use App\Modules\Billing\Http\Controllers\InvoiceController;
use App\Modules\Billing\Http\Controllers\PartsCounterController;
use App\Modules\Billing\Http\Controllers\PurchaseInvoiceController;
use App\Modules\Core\Http\Controllers\CompanyAdminController;
use App\Modules\Core\Http\Controllers\CompanyDeletionController;
use App\Modules\Core\Http\Controllers\CompanySwitchController;
use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\PlanController;
use App\Modules\Core\Http\Controllers\PolarWebhookController;
use App\Modules\Core\Http\Controllers\PublicPlanController;
use App\Modules\Core\Http\Controllers\RegisterController;
use App\Modules\Core\Http\Controllers\SubscriptionCheckoutController;
use App\Modules\Core\Http\Controllers\SubscriptionPlanController;
use App\Modules\Core\Http\Controllers\SuspensionController;
use App\Modules\Core\Http\Controllers\TrialMaintenanceController;
use App\Modules\Core\Http\Controllers\UserController;
use App\Modules\Core\Mail\SubscriptionConfirmedMail;
use App\Modules\Core\Mail\SubscriptionExpiringMail;
use App\Modules\Core\Mail\TrialWelcomeMail;
use App\Modules\CRM\Http\Controllers\CustomerController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\EmployeePortalController;
use App\Modules\Inventory\Http\Controllers\CategoryController;
use App\Modules\Inventory\Http\Controllers\OptionGroupController;
use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Loans\Http\Controllers\LoanController;
use App\Modules\POS\Http\Controllers\PosController;
use App\Modules\POS\Http\Controllers\QuickPosController;
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

// «/register» era la ruta que traía Fortify, hoy desactivada (creaba cuentas sin empresa). Se
// reenvía en vez de dejar un 404: puede estar guardada en marcadores o compartida por ahí.
//
// Se declara con `get` y no con `Route::redirect`, que responde a CUALQUIER método: así un POST a
// la dirección vieja no obtiene una redirección que parezca que algo se envió. La puerta que creaba
// cuentas sueltas queda cerrada de verdad.
Route::get('/register', fn () => redirect('/registro'));

// Planes: pública y FUERA del grupo `guest` a propósito. La consulta un cliente potencial antes de
// registrarse, y también quien ya tiene cuenta y quiere cambiar de plan; con `guest` los segundos
// quedarían fuera. El controlador decide qué botón ve cada uno.
Route::get('/planes', PublicPlanController::class)->name('plans.public');

// Registro self-service (público): un cliente crea su empresa y arranca una prueba gratuita en el
// plan de entrada. `throttle` limita el abuso de altas automáticas. `guest` evita registrarse ya
// logueado.
Route::middleware(['guest'])->group(function (): void {
    Route::get('/registro', [RegisterController::class, 'create'])->name('register.form');
    Route::post('/registro', [RegisterController::class, 'store'])
        ->middleware('throttle:6,1')->name('register.store');

    // Inicio de sesión con Google (Socialite). Solo para cuentas existentes: el callback empareja
    // por correo verificado. `guest` evita reiniciar el flujo si ya hay sesión.
    Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
});

Route::middleware(['auth'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->middleware(['can:dashboard.view', 'subscription'])->name('dashboard');

    // Portal del empleado: es su propia ficha, no requiere permisos de módulo.
    Route::get('/portal/perfil', EmployeePortalController::class)->name('portal.employee');

    // Cuenta y suscripción de la empresa. Solo el Propietario (company.manage): la facturación y el
    // plan son asunto del dueño, no del administrador. Sin el middleware de suscripción: debe seguir
    // siendo accesible aunque la suscripción esté vencida, para poder ver el estado y regularizar.
    Route::get('/panel/cuenta', [PanelController::class, 'account'])
        ->middleware('can:company.manage')->name('panel.account');

    // Contratar el plan. Solo el dueño («company.manage»), igual que la pantalla de cuenta, y sin
    // el middleware de suscripción: hay que poder pagar precisamente cuando está vencida.
    Route::post('/panel/cuenta/contratar/{plan}', SubscriptionCheckoutController::class)
        ->middleware(['can:company.manage', 'throttle:10,1'])->name('panel.account.checkout');

    // Cambiar de plan durante la prueba, sin coste. Mismo permiso y misma ausencia del middleware
    // `subscription`: el propio controlador comprueba que la prueba siga vigente.
    Route::post('/panel/cuenta/plan/{plan}', SubscriptionPlanController::class)
        ->middleware(['can:company.manage', 'throttle:10,1'])->name('panel.account.plan');

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
        Route::get('/categorias', 'categories')->middleware(['can:categories.manage', 'module:inventory'])->name('categories');
        // Grupos de opciones (tamaños, sabores, extras). Exige `products.manage` y no
        // `categories.manage`: aquí se fijan RECARGOS DE PRECIO, que es gestionar producto, no
        // reorganizar el catálogo.
        Route::get('/opciones', 'optionGroups')->middleware(['can:products.manage', 'module:inventory'])->name('option-groups');
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

        // Borrado definitivo de una empresa y todos sus datos. Irreversible: exige contraseña del
        // operador y escribir el nombre de la empresa. `throttle` porque una acción así nunca se
        // repite en ráfaga; si eso ocurre, algo va mal.
        Route::delete('/plataforma/empresas/{company}', CompanyDeletionController::class)
            ->middleware('throttle:5,1')->name('platform.companies.destroy');

        // Planes de suscripción.
        Route::get('/plataforma/planes', [PlanController::class, 'index'])->name('platform.plans');
        Route::post('/plataforma/planes', [PlanController::class, 'store'])->name('platform.plans.store');
        Route::put('/plataforma/planes/{plan}', [PlanController::class, 'update'])->name('platform.plans.update');
        Route::delete('/plataforma/planes/{plan}', [PlanController::class, 'destroy'])->name('platform.plans.destroy');
    });

    // Recibo imprimible de una venta. Lo abre quien puede ver las ventas… o quien las cobra: el
    // cajero no tiene `sales.view` y el botón «Imprimir recibo» del POS le devolvía 403.
    Route::get('/panel/ventas/{sale}/recibo', [SaleController::class, 'receipt'])
        ->middleware(['can:pos.sale-receipt', 'module:sales'])->name('panel.sales.receipt');

    // Recibo en PDF de 80mm (ver en el navegador o ?mode=descargar). Para imprimir, enviar o archivar.
    Route::get('/panel/ventas/{sale}/recibo/pdf/{mode?}', [SaleController::class, 'receiptPdf'])
        ->middleware(['can:pos.sale-receipt', 'module:sales'])->name('panel.sales.receipt.pdf');

    // Exportaciones a CSV: exigen el mismo permiso (y módulo) que ver los datos que exportan.
    Route::controller(ExportController::class)->prefix('panel/exportar')->name('panel.export.')->group(function (): void {
        Route::get('/productos', 'products')->middleware(['can:products.view', 'module:inventory'])->name('products');
        Route::get('/ventas', 'sales')->middleware(['can:sales.view', 'module:sales'])->name('sales');
        Route::get('/clientes', 'customers')->middleware(['can:customers.view', 'module:crm'])->name('customers');
        Route::get('/facturas', 'invoices')->middleware(['can:invoices.view', 'module:billing'])->name('invoices');
        Route::get('/reporte-ventas', 'salesReport')->middleware(['can:reports.view', 'module:reports'])->name('sales-report');
    });

    // Motor de cobro, compartido por las dos pantallas de venta. El módulo se declara como
    // «pos,quick_pos» —basta con tener uno— porque quien contrate solo la venta rápida tiene que
    // poder abrir caja y cobrar igual que quien contrate el mostrador.
    Route::controller(PosController::class)->prefix('panel/pos')->middleware('module:pos,quick_pos')->name('panel.pos.')->group(function (): void {
        // Búsqueda del producto escaneado. Exige el mismo permiso que cobrar: quien no puede
        // operar el POS tampoco tiene por qué consultar precios desde él.
        Route::get('/buscar', 'lookup')->middleware('can:pos.operate')->name('lookup');
        // Búsqueda difusa por SKU/nombre para el mostrador (reemplaza cargar todo el catálogo).
        Route::get('/buscar-productos', 'search')->middleware('can:pos.operate')->name('search');
        Route::post('/abrir-caja', 'openSession')->middleware('can:cash.open')->name('open');
        Route::post('/cobrar', 'checkout')->middleware('can:pos.operate')->name('checkout');
        Route::post('/cerrar-caja', 'closeSession')->middleware('can:cash.close')->name('close');
    });

    // Punto de venta táctil (heladería y comida rápida). Módulo propio y vendible por separado;
    // el cobro lo sigue haciendo el motor compartido de arriba.
    Route::controller(QuickPosController::class)->prefix('panel/pos-rapido')
        ->middleware(['can:pos.operate', 'module:quick_pos', 'subscription'])->name('panel.quick-pos.')
        ->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/catalogo', 'catalog')->name('catalog');
            Route::get('/producto/{product}/opciones', 'options')->name('options');

            // Pedidos aparcados: dejar uno a medias para atender a otro cliente y recuperarlo luego.
            Route::get('/en-espera', 'pending')->name('held.index');
            Route::post('/en-espera', 'hold')->name('held.store');
            Route::get('/en-espera/{heldOrder}', 'resume')->name('held.resume');
            Route::delete('/en-espera/{heldOrder}', 'discard')->name('held.destroy');
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
        // Foto del producto (cacheable). La sirve quien puede ver el catálogo… o quien opera el
        // punto de venta: el cajero no tiene `products.view` y sin esto la rejilla salía sin fotos.
        Route::get('/panel/inventario/{product}/imagen', [ProductController::class, 'image'])
            ->middleware('can:pos.product-image')->name('panel.products.image');
    });

    Route::middleware(['can:products.manage', 'module:inventory'])->group(function (): void {
        Route::post('/panel/inventario', [ProductController::class, 'store'])->name('panel.products.store');
        Route::put('/panel/inventario/{product}', [ProductController::class, 'update'])->name('panel.products.update');
        Route::delete('/panel/inventario/{product}', [ProductController::class, 'destroy'])->name('panel.products.destroy');
        // Borrado múltiple. `throttle` porque vaciar el catálogo no es algo que se repita en ráfaga.
        Route::delete('/panel/inventario', [ProductController::class, 'bulkDestroy'])
            ->middleware('throttle:10,1')->name('panel.products.bulk-destroy');
    });

    // Anular ventas. Permiso propio («sales.void») y no `sales.view`: anular devuelve el stock y
    // saca el cobro de la caja, así que quien consulta el historial no tiene por qué poder deshacerlo.
    Route::middleware(['can:sales.void', 'module:sales'])->group(function (): void {
        Route::delete('/panel/ventas', [SaleController::class, 'bulkVoid'])
            ->middleware('throttle:10,1')->name('panel.sales.bulk-void');
    });

    // Categorías: agrupan el catálogo y ordenan la rejilla del punto de venta. Permiso propio
    // («categories.manage»), distinto de gestionar productos: quien reorganiza el catálogo no tiene
    // por qué poder cambiar precios.
    Route::middleware(['can:categories.manage', 'module:inventory'])->group(function (): void {
        Route::post('/panel/categorias', [CategoryController::class, 'store'])->name('panel.categories.store');
        Route::put('/panel/categorias/{category}', [CategoryController::class, 'update'])->name('panel.categories.update');
        Route::delete('/panel/categorias/{category}', [CategoryController::class, 'destroy'])->name('panel.categories.destroy');
    });

    // Grupos de opciones y sus opciones. Van con «products.manage» —y no con «categories.manage»—
    // porque aquí se fijan recargos: quien puede crear «2 bolas (+60)» está tocando lo que se cobra.
    Route::middleware(['can:products.manage', 'module:inventory'])->group(function (): void {
        Route::post('/panel/opciones', [OptionGroupController::class, 'store'])->name('panel.option-groups.store');
        Route::put('/panel/opciones/{optionGroup}', [OptionGroupController::class, 'update'])->name('panel.option-groups.update');
        Route::delete('/panel/opciones/{optionGroup}', [OptionGroupController::class, 'destroy'])->name('panel.option-groups.destroy');
        Route::put('/panel/opciones/{optionGroup}/productos', [OptionGroupController::class, 'syncProducts'])->name('panel.option-groups.products');

        Route::post('/panel/opciones/{optionGroup}/opcion', [OptionGroupController::class, 'storeOption'])->name('panel.options.store');
        Route::put('/panel/opcion/{option}', [OptionGroupController::class, 'updateOption'])->name('panel.options.update');
        Route::delete('/panel/opcion/{option}', [OptionGroupController::class, 'destroyOption'])->name('panel.options.destroy');
    });

    // Perfil del cliente y ver/descargar sus documentos: basta con poder ver el CRM.
    Route::middleware(['can:customers.view', 'module:crm'])->group(function (): void {
        Route::get('/panel/crm/{customer}', [CustomerController::class, 'show'])->name('panel.customers.show');
        Route::get('/panel/crm/{customer}/documentos/{document}', [CustomerController::class, 'showDocument'])
            ->name('panel.customers.documents.show');
    });

    Route::middleware(['can:customers.manage', 'module:crm'])->group(function (): void {
        Route::post('/panel/crm', [CustomerController::class, 'store'])->name('panel.customers.store');
        Route::put('/panel/crm/{customer}', [CustomerController::class, 'update'])->name('panel.customers.update');
        Route::delete('/panel/crm/{customer}', [CustomerController::class, 'destroy'])->name('panel.customers.destroy');
        // Subir/eliminar documentos del perfil.
        Route::post('/panel/crm/{customer}/documentos', [CustomerController::class, 'storeDocument'])
            ->name('panel.customers.documents.store');
        Route::delete('/panel/crm/{customer}/documentos/{document}', [CustomerController::class, 'destroyDocument'])
            ->name('panel.customers.documents.destroy');

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

    // Recibo del cobro (imprimible + PDF 80mm). Es lectura: basta loans.view.
    Route::middleware(['can:loans.view', 'module:loans'])->group(function (): void {
        Route::get('/panel/prestamos/{loan}/pagos/{payment}/recibo', [LoanController::class, 'receipt'])
            ->name('panel.loans.receipt');
        Route::get('/panel/prestamos/{loan}/pagos/{payment}/recibo/pdf/{mode?}', [LoanController::class, 'receiptPdf'])
            ->name('panel.loans.receipt.pdf');
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

        // Comprobantes de compra (facturas recibidas) → envío 606 de la DGII. Ver/exportar exige
        // purchase_invoices.view; subir/editar/borrar exige purchase_invoices.manage.
        Route::get('/panel/compras-606', [PurchaseInvoiceController::class, 'index'])
            ->middleware('can:purchase_invoices.view')->name('panel.purchase-invoices');
        Route::get('/panel/compras-606/exportar/606', [PurchaseInvoiceController::class, 'export606'])
            ->middleware('can:purchase_invoices.view')->name('panel.purchase-invoices.606');
        Route::get('/panel/compras-606/exportar/excel', [PurchaseInvoiceController::class, 'exportExcel'])
            ->middleware('can:purchase_invoices.view')->name('panel.purchase-invoices.excel');
        Route::get('/panel/compras-606/{purchaseInvoice}/archivo', [PurchaseInvoiceController::class, 'showFile'])
            ->middleware('can:purchase_invoices.view')->name('panel.purchase-invoices.file');
        Route::post('/panel/compras-606', [PurchaseInvoiceController::class, 'store'])
            ->middleware('can:purchase_invoices.manage')->name('panel.purchase-invoices.store');
        Route::put('/panel/compras-606/{purchaseInvoice}', [PurchaseInvoiceController::class, 'update'])
            ->middleware('can:purchase_invoices.manage')->name('panel.purchase-invoices.update');
        Route::delete('/panel/compras-606/{purchaseInvoice}', [PurchaseInvoiceController::class, 'destroy'])
            ->middleware('can:purchase_invoices.manage')->name('panel.purchase-invoices.destroy');
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

// Webhook entrante de Polar: pagos, altas y bajas de suscripción (sin sesión; protegido por firma
// HMAC sobre el cuerpo, que es lo único que impide que alguien se active un plan con un POST).
Route::post('/webhooks/polar', PolarWebhookController::class)->name('webhooks.polar');

// Mantenimiento disparado por el cron de Vercel (sin sesión; protegido por CRON_SECRET en el header).
// Purga los datos de las pruebas self-service vencidas hace más de 24 h.
Route::get('/tareas/purgar-pruebas', [TrialMaintenanceController::class, 'purgeTrials'])->name('tasks.purge-trials');

// Avisa por correo a las suscripciones de pago por vencer (sin sesión; protegido por CRON_SECRET).
Route::get('/tareas/avisar-vencimientos', [TrialMaintenanceController::class, 'remindExpiring'])->name('tasks.remind-expiring');

// Previsualización de correos (SOLO local): abre el HTML del correo en el navegador para revisar el
// diseño sin tener que registrarse. Nunca se activa en producción.
if (app()->environment('local')) {
    Route::get('/preview/correo-bienvenida', fn () => new TrialWelcomeMail(
        ownerName: 'Yael',
        companyName: 'Colmado La Bendición',
        trialDays: (int) config('bmos.trial.days', 15),
        trialEndsAt: now()->addDays(15),
        purgeAt: now()->addDays(16),
        moduleLabels: ['Punto de Venta', 'Inventario', 'Préstamos', 'CRM'],
        loginUrl: route('login'),
        supportWhatsapp: (string) config('platform.support_whatsapp'),
        supportEmail: (string) config('platform.support_email'),
    ));

    Route::get('/preview/correo-confirmacion', fn () => new SubscriptionConfirmedMail(
        ownerName: 'Yael',
        companyName: 'Colmado La Bendición',
        planName: 'Pro',
        planPrice: '3500.00',
        billingCycleLabel: 'Mensual',
        renewsAt: now()->addMonth(),
        moduleLabels: ['Punto de Venta', 'Inventario', 'Ventas', 'CRM', 'Facturación', 'Finanzas'],
        loginUrl: route('login'),
        supportWhatsapp: (string) config('platform.support_whatsapp'),
        supportEmail: (string) config('platform.support_email'),
    ));

    Route::get('/preview/correo-vencimiento', fn () => new SubscriptionExpiringMail(
        ownerName: 'Yael',
        companyName: 'Colmado La Bendición',
        planName: 'Pro',
        renewsAt: now()->addDays(4),
        daysLeft: 4,
        loginUrl: route('login'),
        supportWhatsapp: (string) config('platform.support_whatsapp'),
        supportEmail: (string) config('platform.support_email'),
    ));
}
