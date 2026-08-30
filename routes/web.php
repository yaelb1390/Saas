<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\PanelController;
use App\Modules\AI\Http\Controllers\AiAssistantController;
use App\Modules\AI\Http\Controllers\AiSettingsController;
use App\Modules\Billing\Http\Controllers\DgiiReportController;
use App\Modules\Billing\Http\Controllers\InvoiceController;
use App\Modules\Billing\Http\Controllers\PartsCounterController;
use App\Modules\Billing\Http\Controllers\PurchaseInvoiceController;
use App\Modules\Core\Http\Controllers\CompanyAdminController;
use App\Modules\Core\Http\Controllers\CompanyDeletionController;
use App\Modules\Core\Http\Controllers\CompanyProfileController;
use App\Modules\Core\Http\Controllers\CompanySwitchController;
use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\MonitoringController;
use App\Modules\Core\Http\Controllers\PlanController;
use App\Modules\Core\Http\Controllers\PolarWebhookController;
use App\Modules\Core\Http\Controllers\PublicPlanController;
use App\Modules\Core\Http\Controllers\RegisterController;
use App\Modules\Core\Http\Controllers\SubscriptionCheckoutController;
use App\Modules\Core\Http\Controllers\SubscriptionPlanController;
use App\Modules\Core\Http\Controllers\SubscriptionStatusController;
use App\Modules\Core\Http\Controllers\SuspensionController;
use App\Modules\Core\Http\Controllers\TrialMaintenanceController;
use App\Modules\Core\Http\Controllers\UserController;
use App\Modules\Core\Mail\SubscriptionConfirmedMail;
use App\Modules\Core\Mail\SubscriptionExpiringMail;
use App\Modules\Core\Mail\TrialWelcomeMail;
use App\Modules\CRM\Http\Controllers\CustomerController;
use App\Modules\Dealer\Http\Controllers\VehicleController;
use App\Modules\Dealer\Http\Controllers\VehicleDealController;
use App\Modules\Dealer\Http\Controllers\VehicleDocumentController;
use App\Modules\Dealer\Http\Controllers\VehicleJobController;
use App\Modules\Dealer\Http\Controllers\VehiclePhotoController;
use App\Modules\Delivery\Http\Controllers\DeliveryController;
use App\Modules\Delivery\Http\Controllers\DriverPortalController;
use App\Modules\Finance\Http\Controllers\ExpenseController;
use App\Modules\Help\Http\Controllers\AssistantController;
use App\Modules\Help\Http\Controllers\HelpController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\EmployeePortalController;
use App\Modules\Inventory\Http\Controllers\CategoryController;
use App\Modules\Inventory\Http\Controllers\OptionGroupController;
use App\Modules\Inventory\Http\Controllers\ProductAvailabilityController;
use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Loans\Http\Controllers\LoanApplicationController;
use App\Modules\Loans\Http\Controllers\LoanController;
use App\Modules\POS\Http\Controllers\OfflineSyncController;
use App\Modules\POS\Http\Controllers\PosController;
use App\Modules\POS\Http\Controllers\QuickPosController;
use App\Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use App\Modules\Quotes\Http\Controllers\PublicQuoteController;
use App\Modules\Quotes\Http\Controllers\QuoteController;
use App\Modules\Sales\Http\Controllers\SaleController;
use App\Modules\Social\Http\Controllers\SocialAutomationController;
use App\Modules\Social\Http\Controllers\SocialController;
use App\Modules\Social\Http\Controllers\ZernioWebhookController;
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

    /*
     * Portal del repartidor: SUS entregas, en su móvil.
     *
     * Va con `delivery.own` y NO con `module:delivery`. El módulo dice si la empresa contrató el
     * reparto para administrarlo desde el panel; esto es la otra punta: el motorista solo cierra lo
     * que ya le asignaron. Si el módulo se apagara, quedarían entregas vivas en la calle sin forma
     * de cerrarlas y el dinero cobrado sin poder anotarse.
     *
     * El aislamiento real no lo da el permiso sino el controlador, que filtra por la ficha de
     * empleado del usuario: `delivery.own` lo tienen también el dueño y el administrador.
     */
    Route::middleware('can:delivery.own')->group(function (): void {
        Route::get('/portal/entregas', [DriverPortalController::class, 'index'])->name('portal.deliveries');
        Route::post('/portal/entregas/{delivery}/cerrar', [DriverPortalController::class, 'close'])
            ->name('portal.deliveries.close');
    });

    // Cuenta y suscripción de la empresa. Solo el Propietario (company.manage): la facturación y el
    // plan son asunto del dueño, no del administrador. Sin el middleware de suscripción: debe seguir
    // siendo accesible aunque la suscripción esté vencida, para poder ver el estado y regularizar.
    Route::get('/panel/cuenta', [PanelController::class, 'account'])
        ->middleware('can:company.manage')->name('panel.account');

    // Contratar el plan. Solo el dueño («company.manage»), igual que la pantalla de cuenta, y sin
    // el middleware de suscripción: hay que poder pagar precisamente cuando está vencida.
    Route::post('/panel/cuenta/contratar/{plan}', SubscriptionCheckoutController::class)
        ->middleware(['can:company.manage', 'throttle:10,1'])->name('panel.account.checkout');

    // Estado de la suscripción, para que la pantalla de cuenta se entere sola de que el pago ya se
    // confirmó en vez de pedirle al cliente que recargue. Es de LECTURA: no activa nada.
    //
    // Sin `subscription`, como el resto de esta zona: hay que poder consultarla precisamente cuando
    // está vencida, que es cuando alguien acaba de pagar para reactivarla.
    Route::get('/panel/cuenta/estado', SubscriptionStatusController::class)
        ->middleware('can:company.manage')->name('panel.account.status');

    // Cambiar de plan durante la prueba, sin coste. Mismo permiso y misma ausencia del middleware
    // `subscription`: el propio controlador comprueba que la prueba siga vigente.
    Route::post('/panel/cuenta/plan/{plan}', SubscriptionPlanController::class)
        ->middleware(['can:company.manage', 'throttle:10,1'])->name('panel.account.plan');

    /*
     * Datos de la empresa: lo que sale impreso en cada recibo que recibe un cliente.
     *
     * Mismo permiso que la cuenta (`company.manage`): cambiar la razón social o el RNC de la empresa
     * es tan delicado como tocar la suscripción, y un cajero no tiene por qué poder hacerlo.
     *
     * El logo se sirve por su propia ruta y NO exige `company.manage`: lo pinta el recibo, que
     * también miran los cajeros al cobrar. Basta con estar dentro y pertenecer a la empresa, que es
     * lo que ya garantiza la sesión.
     */
    Route::get('/panel/mi-empresa', [CompanyProfileController::class, 'edit'])
        ->middleware('can:company.manage')->name('panel.company-profile');
    Route::put('/panel/mi-empresa', [CompanyProfileController::class, 'update'])
        ->middleware('can:company.manage')->name('panel.company-profile.update');
    Route::delete('/panel/mi-empresa/logo', [CompanyProfileController::class, 'deleteLogo'])
        ->middleware('can:company.manage')->name('panel.company-profile.logo.destroy');

    Route::get('/panel/empresa/logo', [CompanyProfileController::class, 'logo'])->name('panel.company.logo');

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
        // Tamaños y sabores van con «quick_pos», no con «inventory»: solo el terminal táctil los
        // pregunta al vender. Ocultarlos del menú no basta —la URL seguiría abierta—, así que la
        // puerta de verdad es esta.
        Route::get('/opciones', 'optionGroups')->middleware(['can:products.manage', 'module:quick_pos', 'feature:option_groups'])->name('option-groups');
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

    /*
     * Ayuda: cómo se usa el sistema.
     *
     * SIN `can:` y SIN `module:`, y es deliberado. La ayuda de uso no es una función que se venda:
     * es lo que evita que el cliente nuevo abandone en la primera semana, y quien más la necesita es
     * justo el del plan más barato, que es el que se quedaría fuera de cualquier puerta que pusiéramos.
     *
     * Lo que sí se filtra es el CONTENIDO: `HelpSearch` solo devuelve artículos de los módulos que esa
     * empresa contrató y de lo que ese usuario puede hacer.
     */
    Route::get('/panel/ayuda', [HelpController::class, 'index'])->name('panel.help');
    Route::get('/panel/ayuda/{slug}', [HelpController::class, 'show'])->name('panel.help.article');

    /*
     * El asistente de la burbuja. Misma ayuda, pero sin salir de la pantalla y con hilo.
     *
     * `throttle:20,1` porque cada pregunta sale a la red a un proveedor que se paga por uso: es la
     * misma regla que ya se aplica a la sincronización de redes sociales. El tope DIARIO por empresa
     * es otra cosa y vive en `AssistantQuota`; este de aquí es contra la ráfaga, no contra el gasto.
     *
     * Sin `can:` ni `module:`, igual que la ayuda: quien decide quién lo tiene es el interruptor por
     * empresa, y lo comprueba el propio controlador.
     */
    Route::post('/panel/asistente', [AssistantController::class, 'ask'])
        ->middleware('throttle:20,1')->name('panel.assistant.ask');
    Route::delete('/panel/asistente', [AssistantController::class, 'reset'])->name('panel.assistant.reset');

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
        // El asistente de ayuda de esa empresa. Lo enciende el operador porque él paga cada pregunta.
        Route::post('/plataforma/empresas/{company}/asistente', [CompanyAdminController::class, 'toggleAssistant'])->name('platform.companies.assistant');

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

        // Ajustes de IA de la PLATAFORMA: qué proveedor y con qué clave. Una sola para todas las
        // empresas — un dueño de colmado no va a sacar una clave en Google AI Studio, y exigírselo
        // dejaría el módulo apagado para todo el mundo.
        Route::get('/plataforma/ia', [AiSettingsController::class, 'index'])->name('platform.ai');
        Route::put('/plataforma/ia', [AiSettingsController::class, 'update'])->name('platform.ai.update');
        Route::post('/plataforma/ia/probar', [AiSettingsController::class, 'probar'])->name('platform.ai.test');
        Route::post('/plataforma/ia/reindexar', [AiSettingsController::class, 'reindexar'])->name('platform.ai.reindex');

        // Monitoreo de la plataforma: salud, actividad y errores. Mira TODAS las empresas, no la
        // que el operador tenga abierta.
        Route::get('/plataforma/monitoreo', MonitoringController::class)->name('platform.monitoring');
        Route::post('/plataforma/monitoreo/limpiar', [MonitoringController::class, 'limpiar'])
            ->name('platform.monitoring.clean');
    });

    // Recibo imprimible de una venta. Lo abre quien puede ver las ventas… o quien las cobra: el
    // cajero no tiene `sales.view` y el botón «Imprimir recibo» del POS le devolvía 403.
    Route::get('/panel/ventas/{sale}/recibo', [SaleController::class, 'receipt'])
        ->middleware(['can:pos.sale-receipt', 'module:sales'])->name('panel.sales.receipt');

    // Recibo en PDF de 80mm (ver en el navegador o ?mode=descargar). Para imprimir, enviar o archivar.
    Route::get('/panel/ventas/{sale}/recibo/pdf/{mode?}', [SaleController::class, 'receiptPdf'])
        ->middleware(['can:pos.sale-receipt', 'module:sales'])->name('panel.sales.receipt.pdf');

    /*
     * Cotizaciones.
     *
     * «convert» va con su propio permiso —como anular una venta— porque descuenta existencias y mete
     * dinero en la caja. Quien prepara una oferta no tiene por qué poder cobrarla.
     */
    Route::controller(QuoteController::class)->prefix('panel/cotizaciones')
        ->middleware('module:quotes')->name('panel.quotes.')->group(function (): void {
            Route::get('/', 'index')->middleware('can:quotes.view')->name('index');
            Route::get('/nueva', 'create')->middleware('can:quotes.manage')->name('create');
            Route::post('/', 'store')->middleware('can:quotes.manage')->name('store');
            Route::get('/{quote}', 'show')->middleware('can:quotes.view')->name('show');
            Route::get('/{quote}/pdf/{mode?}', 'pdf')->middleware('can:quotes.view')->name('pdf');
            Route::post('/{quote}/enviar', 'send')->middleware(['can:quotes.send', 'throttle:20,1'])->name('send');
            Route::put('/{quote}/estado', 'status')->middleware('can:quotes.manage')->name('status');
            Route::post('/{quote}/cobrar', 'convert')->middleware('can:quotes.convert')->name('convert');
        });

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
        // Copia del catálogo para poder cobrar sin conexión. Ver PosController::catalogo().
        Route::get('/catalogo', 'catalogo')->middleware('can:pos.operate')->name('catalogo');
        Route::post('/abrir-caja', 'openSession')->middleware('can:cash.open')->name('open');
        Route::post('/cobrar', 'checkout')->middleware('can:pos.operate')->name('checkout');

        // Cambiar el almacén del turno sin cerrar la caja: una jornada dura ocho horas y quien se
        // equivoca a las nueve no debería tener que cuadrar el efectivo para corregirlo.
        Route::post('/almacen', 'changeWarehouse')->middleware('can:pos.operate')->name('warehouse');
        Route::post('/cerrar-caja', 'closeSession')->middleware('can:cash.close')->name('close');
    });

    /*
     * Las ventas que se cobraron sin internet.
     *
     * Van en el mismo prefijo que el resto del POS y con el mismo permiso —quien puede cobrar puede
     * subir lo cobrado—, pero en su propio controlador: lo que entra por aquí ya ocurrió, y por eso
     * se acepta el precio que trae el navegador. En ningún otro sitio del sistema se hace eso, y
     * mezclarlo con el cobro normal invitaría a copiarlo donde no toca.
     *
     * El límite de peticiones es generoso a propósito: un terminal que recupera la línea después de
     * media jornada a oscuras tiene derecho a reintentar sin que se le cierre la puerta.
     */
    Route::controller(OfflineSyncController::class)->prefix('panel/pos')
        ->middleware(['can:pos.operate', 'module:pos,quick_pos'])->name('panel.pos.offline.')
        ->group(function (): void {
            Route::get('/sesion', 'estado')->name('estado');
            Route::post('/sincronizar', 'sincronizar')->middleware('throttle:60,1')->name('sync');
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
        // Contar y ajustar la existencia de un producto. Mismo permiso que dar entrada: mover
        // existencias es lo que permite tapar un faltante.
        Route::post('/panel/inventario/{product}/existencia', [StockController::class, 'count'])
            ->middleware('can:stock.adjust')->name('panel.stock.count');
        // Foto del producto (cacheable). La sirve quien puede ver el catálogo… o quien opera el
        // punto de venta: el cajero no tiene `products.view` y sin esto la rejilla salía sin fotos.
        Route::get('/panel/inventario/{product}/imagen', [ProductController::class, 'image'])
            ->middleware('can:pos.product-image')->name('panel.products.image');

        /*
         * «Hoy no hay». Va con `products.view` —lo que ya tiene quien opera el terminal— y NO con
         * `products.manage`.
         *
         * Es deliberado: que se acabó el guineo lo sabe el cajero a las tres de la tarde, no el dueño
         * desde su casa. Apagar lo que se acabó es operación; retirar un producto del catálogo sigue
         * siendo gestión y sigue exigiendo el otro permiso.
         */
        Route::post('/panel/inventario/{product}/disponible', ProductAvailabilityController::class)
            ->middleware('can:products.view')->name('panel.products.availability');
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
    //
    // Y con «quick_pos», igual que la pantalla que los edita: sin el terminal táctil no hay dónde
    // preguntarlos. Si aquí se quedara «inventory», quien no contrató comida rápida no vería el
    // menú pero podría crear grupos a mano contra estas rutas.
    Route::middleware(['can:products.manage', 'module:quick_pos', 'feature:option_groups'])->group(function (): void {
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
        // La ficha vive FUERA del módulo: lee ventas, facturas y entregas, y meter eso dentro de CRM
        // lo obligaría a conocer otros tres dominios. Mismo motivo que CustomerPortalController.
        Route::get('/panel/crm/{customer}', CustomerProfileController::class)->name('panel.customers.show');
        Route::get('/panel/crm/{customer}/documentos/{document}', [CustomerController::class, 'showDocument'])
            ->name('panel.customers.documents.show');
    });

    Route::middleware(['can:customers.manage', 'module:crm'])->group(function (): void {
        Route::post('/panel/crm', [CustomerController::class, 'store'])->name('panel.customers.store');
        Route::put('/panel/crm/{customer}', [CustomerController::class, 'update'])->name('panel.customers.update');
        // Archivar y eliminar dejan de ser lo mismo. Archivar es reversible y no rompe nada;
        // eliminar solo vale para la ficha creada por error, y ambas comprueban cosas de otros
        // módulos, así que viven en el controlador de nivel aplicación.
        Route::post('/panel/crm/{customer}/estado', [CustomerProfileController::class, 'toggle'])
            ->name('panel.customers.toggle');
        Route::delete('/panel/crm/{customer}', [CustomerProfileController::class, 'destroy'])->name('panel.customers.destroy');
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

    /*
     * Dealer de vehículos.
     *
     * Tres permisos y no uno solo: `vehicles.*` es el patio —y el que abre el COSTO y el margen—,
     * `vehicle_deals.*` mueve dinero, y `vehicle_jobs.*` es el taller. El mecánico que anota un
     * cambio de gomas no tiene por qué poder vender el carro.
     */
    Route::middleware('module:dealer')->group(function (): void {
        Route::middleware('can:vehicles.view')->group(function (): void {
            Route::get('/panel/vehiculos', [VehicleController::class, 'index'])->name('panel.vehicles');
            // Las filas de la rejilla. Mismo permiso que la pantalla: el JSON decide por sí mismo
            // si incluye el costo, así que ver la lista no es ver los costos.
            Route::get('/panel/vehiculos/datos', [VehicleController::class, 'datos'])->name('panel.vehicles.data');
            // Exportar y agrupar en el SERVIDOR: las dos cosas son de la edición de pago de AG Grid,
            // y hechas aquí abarcan el inventario filtrado entero y no solo lo que se descargó.
            Route::get('/panel/vehiculos/exportar', [VehicleController::class, 'exportar'])->name('panel.vehicles.export');
            Route::get('/panel/vehiculos/agrupar', [VehicleController::class, 'agrupar'])->name('panel.vehicles.group');
            // La ficha de una unidad: lo que no cabe en la tabla. Se pide solo al abrirla.
            Route::get('/panel/vehiculos/{vehicle}/ficha', [VehicleController::class, 'ficha'])->name('panel.vehicles.ficha');
            /*
             * La foto. Va por aquí y no por una URL pública del disco a propósito: así pasa por el
             * ámbito de empresa —la unidad de otro negocio ni se resuelve— y por el permiso. Una
             * dirección pública sería adivinable y filtraría fotos entre empresas.
             */
            Route::get('/panel/vehiculos/{vehicle}/foto', [VehicleController::class, 'foto'])->name('panel.vehicles.photo');
        });

        Route::post('/panel/vehiculos', [VehicleController::class, 'store'])
            ->middleware('can:vehicles.manage')->name('panel.vehicles.store');

        Route::put('/panel/vehiculos/{vehicle}', [VehicleController::class, 'update'])
            ->middleware('can:vehicles.manage')->name('panel.vehicles.update');

        /*
         * La galería.
         *
         * Ver las fotos va con `vehicles.view`: enseñarle el carro a un cliente es justo lo que hace
         * quien atiende. Cambiarlas exige administrar.
         */
        Route::middleware('can:vehicles.view')->group(function (): void {
            Route::get('/panel/vehiculos/{vehicle}/fotos', [VehiclePhotoController::class, 'index'])->name('panel.vehicles.photos');
            Route::get('/panel/vehiculos/{vehicle}/fotos/{photo}', [VehiclePhotoController::class, 'show'])->name('panel.vehicles.photos.show');
        });

        Route::middleware('can:vehicles.manage')->group(function (): void {
            Route::post('/panel/vehiculos/{vehicle}/fotos', [VehiclePhotoController::class, 'store'])->name('panel.vehicles.photos.store');
            Route::post('/panel/vehiculos/{vehicle}/fotos/orden', [VehiclePhotoController::class, 'reordenar'])->name('panel.vehicles.photos.order');
            Route::post('/panel/vehiculos/{vehicle}/fotos/{photo}/principal', [VehiclePhotoController::class, 'principal'])->name('panel.vehicles.photos.primary');
            Route::delete('/panel/vehiculos/{vehicle}/fotos/{photo}', [VehiclePhotoController::class, 'destroy'])->name('panel.vehicles.photos.destroy');
        });

        /*
         * Los documentos, TODOS con `vehicles.manage`, incluido verlos.
         *
         * Aquí dentro va la matrícula con los datos del titular, la factura con el precio real y el
         * contrato con la cédula del comprador. Enseñar el carro es una cosa; abrir sus papeles, otra.
         */
        Route::middleware('can:vehicles.manage')->group(function (): void {
            Route::get('/panel/vehiculos/{vehicle}/documentos', [VehicleDocumentController::class, 'index'])->name('panel.vehicles.documents');
            Route::post('/panel/vehiculos/{vehicle}/documentos', [VehicleDocumentController::class, 'store'])->name('panel.vehicles.documents.store');
            Route::get('/panel/vehiculos/{vehicle}/documentos/{document}', [VehicleDocumentController::class, 'show'])->name('panel.vehicles.documents.show');
            Route::delete('/panel/vehiculos/{vehicle}/documentos/{document}', [VehicleDocumentController::class, 'destroy'])->name('panel.vehicles.documents.destroy');
        });

        Route::get('/panel/vehiculos/tratos', [VehicleDealController::class, 'index'])
            ->middleware('can:vehicle_deals.view')->name('panel.vehicle-deals');

        Route::middleware('can:vehicle_deals.manage')->group(function (): void {
            Route::post('/panel/vehiculos/tratos', [VehicleDealController::class, 'store'])->name('panel.vehicle-deals.store');
            Route::post('/panel/vehiculos/tratos/{deal}/cerrar', [VehicleDealController::class, 'close'])->name('panel.vehicle-deals.close');
            Route::post('/panel/vehiculos/tratos/{deal}/anular', [VehicleDealController::class, 'cancel'])->name('panel.vehicle-deals.cancel');
            Route::post('/panel/vehiculos/tratos/{deal}/abonos', [VehicleDealController::class, 'payment'])->name('panel.vehicle-deals.payments.store');
        });

        Route::get('/panel/vehiculos/taller', [VehicleJobController::class, 'index'])
            ->middleware('can:vehicle_jobs.view')->name('panel.vehicle-jobs');

        Route::middleware('can:vehicle_jobs.manage')->group(function (): void {
            Route::post('/panel/vehiculos/taller', [VehicleJobController::class, 'store'])->name('panel.vehicle-jobs.store');
            Route::post('/panel/vehiculos/taller/{job}/hecho', [VehicleJobController::class, 'complete'])->name('panel.vehicle-jobs.complete');
        });
    });

    /*
     * Solicitudes de préstamo. El permiso está partido en dos a propósito, y es la única razón por
     * la que la evaluación sirve de algo:
     *
     *   - Recibir la solicitud y tomar los datos (ingresos, garante) va con `loan_applications.*`.
     *   - Aprobar, rechazar y desembolsar van con `loans.manage`, el permiso que YA gobierna el
     *     dinero en este módulo.
     *
     * Así, quien atiende el mostrador no puede concederse un préstamo a sí mismo, que es justo lo
     * que hace falta separar. No se inventa un `loan_applications.decide` porque decidir sobre una
     * solicitud es decidir sobre dinero, y ese permiso ya existe.
     */
    Route::middleware(['can:loan_applications.view', 'module:loans'])->group(function (): void {
        Route::get('/panel/solicitudes', [LoanApplicationController::class, 'index'])->name('panel.loan-applications');
        Route::get('/panel/solicitudes/{application}', [LoanApplicationController::class, 'show'])->name('panel.loan-applications.show');
    });

    Route::middleware(['can:loan_applications.manage', 'module:loans'])->group(function (): void {
        Route::post('/panel/solicitudes', [LoanApplicationController::class, 'store'])->name('panel.loan-applications.store');
        Route::put('/panel/solicitudes/{application}/evaluacion', [LoanApplicationController::class, 'evaluate'])->name('panel.loan-applications.evaluate');
        Route::post('/panel/solicitudes/{application}/desistir', [LoanApplicationController::class, 'cancelApplication'])->name('panel.loan-applications.cancel');
    });

    Route::middleware(['can:loans.manage', 'module:loans'])->group(function (): void {
        Route::post('/panel/solicitudes/{application}/aprobar', [LoanApplicationController::class, 'approve'])->name('panel.loan-applications.approve');
        Route::post('/panel/solicitudes/{application}/rechazar', [LoanApplicationController::class, 'reject'])->name('panel.loan-applications.reject');
        Route::post('/panel/solicitudes/{application}/reabrir', [LoanApplicationController::class, 'reopen'])->name('panel.loan-applications.reopen');
        // La única de todo el grupo que saca dinero de la caja.
        Route::post('/panel/solicitudes/{application}/desembolsar', [LoanApplicationController::class, 'disburse'])->name('panel.loan-applications.disburse');
    });

    /*
     * Gastos. Ver va con `finance.view` y anotar/anular con `finance.manage`, los dos permisos que ya
     * gobiernan Finanzas: un gasto no es una categoría nueva de riesgo, es exactamente lo mismo que
     * ya protegían —el dinero de la empresa—.
     */
    Route::middleware(['can:finance.view', 'module:finance'])->group(function (): void {
        Route::get('/panel/gastos', [ExpenseController::class, 'index'])->name('panel.expenses');
    });

    Route::middleware(['can:finance.manage', 'module:finance'])->group(function (): void {
        Route::post('/panel/gastos', [ExpenseController::class, 'store'])->name('panel.expenses.store');
        // Anular devuelve el dinero al saldo y, si salió del cajón, al turno.
        Route::delete('/panel/gastos/{expense}', [ExpenseController::class, 'destroy'])->name('panel.expenses.destroy');

        Route::post('/panel/gastos/conceptos', [ExpenseController::class, 'storeCategory'])->name('panel.expense-categories.store');
        Route::put('/panel/gastos/conceptos/{category}', [ExpenseController::class, 'updateCategory'])->name('panel.expense-categories.update');
    });

    /*
     * Redes sociales: publicar en Instagram, Facebook y demás desde el panel.
     *
     * Tres permisos y no uno, porque son tres riesgos distintos: VER qué se publicó es inofensivo;
     * PUBLICAR es hablar en nombre del negocio ante todo el mundo; y CONECTAR mueve las credenciales
     * de las redes. Quien consulta no tiene por qué poder escribir, y quien escribe no tiene por qué
     * poder desconectar la cuenta.
     */
    Route::middleware('module:social')->group(function (): void {
        Route::get('/panel/redes', [SocialController::class, 'index'])
            ->middleware('can:social.view')->name('panel.social');

        Route::post('/panel/redes/publicar', [SocialController::class, 'publish'])
            ->middleware('can:social.publish')->name('panel.social.publish');
        Route::post('/panel/redes/imagen', [SocialController::class, 'presign'])
            ->middleware('can:social.publish')->name('panel.social.presign');
        // Cancelar lo programado es la vuelta atrás de publicar, así que va con el mismo permiso:
        // quien puede decidir que algo salga puede decidir que ya no.
        Route::delete('/panel/redes/publicaciones/{post}', [SocialController::class, 'cancelPost'])
            ->middleware('can:social.publish')->name('panel.social.posts.cancel');

        /*
         * Respuestas automáticas a comentarios y mensajes.
         *
         * Van con `social.publish` y no con un permiso propio: una automatización publica en nombre
         * del negocio igual que un post, solo que sin nadie delante y para siempre. Quien no puede
         * publicar tampoco debería poder dejar montado algo que publique solo.
         */
        Route::controller(SocialAutomationController::class)
            ->prefix('panel/redes/automaticas')->name('panel.social.automations')
            ->middleware('can:social.publish')->group(function (): void {
                Route::get('/', 'index')->name('');
                Route::post('/', 'store')->name('.store');
                // Trae de la red lo publicado desde el móvil, para poder colgarle una automatización.
                // `throttle` porque cada llamada va a Instagram a leer el historial.
                Route::post('/sincronizar', 'syncPosts')
                    ->middleware('throttle:10,1')->name('.sync');
                // «Reporte» y no «registro»: es la palabra que el dueño busca, y la pantalla ya no
                // es solo la lista de disparos. Nadie enlazaba la anterior, así que no rompe nada.
                Route::get('/{automation}/reporte', 'reporte')->name('.reporte');
                Route::put('/{automation}', 'update')->name('.update');
                // Apagar tiene su propia puerta: quien ve que está contestando mal necesita un clic,
                // no abrir un formulario y volver a guardarlo entero.
                Route::post('/{automation}/estado', 'toggle')->name('.toggle');
                Route::delete('/{automation}', 'destroy')->name('.destroy');
            });

        // La clave es una credencial de la empresa: mismo permiso que conectar cuentas.
        Route::put('/panel/redes/clave', [SocialController::class, 'saveKey'])
            ->middleware('can:social.connect')->name('panel.social.key');
        Route::post('/panel/redes/conectar', [SocialController::class, 'connect'])
            ->middleware('can:social.connect')->name('panel.social.connect');

        // La bienvenida manda mensajes en nombre de la empresa, así que va con el permiso de
        // publicar y no con el de mirar.
        Route::put('/panel/redes/bienvenida', [SocialController::class, 'saveWelcome'])
            ->middleware('can:social.publish')->name('panel.social.welcome');
    });

    /*
     * Reparto. Hasta ahora la única ruta de Entregas era la pantalla de solo lectura: no había forma
     * de crear una, ni de asignarla, ni de cambiarle el estado, así que la tabla solo podía estar
     * vacía. Estas son las puertas que faltaban.
     *
     * Van con `delivery.manage`: asignar el reparto y, sobre todo, dar por cobrada y liquidada una
     * entrega es mover dinero, y no es tarea de quien solo consulta el estado de un pedido.
     */
    Route::middleware(['can:delivery.manage', 'module:delivery'])->group(function (): void {
        Route::post('/panel/entregas', [DeliveryController::class, 'store'])->name('panel.deliveries.store');
        Route::post('/panel/entregas/{delivery}/asignar', [DeliveryController::class, 'assign'])->name('panel.deliveries.assign');
        Route::post('/panel/entregas/{delivery}/estado', [DeliveryController::class, 'transition'])->name('panel.deliveries.transition');
        Route::post('/panel/entregas/{delivery}/cobrada', [DeliveryController::class, 'collect'])->name('panel.deliveries.collect');
        Route::post('/panel/entregas/liquidar/{employee}', [DeliveryController::class, 'settle'])->name('panel.deliveries.settle');
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
        // Desvincular es la otra mitad del mismo permiso, que decía «vincula/desvincula» y solo
        // sabía hacer lo primero.
        Route::post('/panel/whatsapp/desvincular', [WhatsAppController::class, 'disconnect'])
            ->middleware('can:whatsapp.connect')->name('panel.whatsapp.disconnect');
        // Lo que el asistente sabe del negocio es lo que le promete a los clientes: mismo permiso
        // que administrar la línea, no el de escribir en ella.
        Route::post('/panel/whatsapp/asistente', [WhatsAppController::class, 'saveBot'])
            ->middleware('can:whatsapp.connect')->name('panel.whatsapp.bot');
        // Devolverle una conversación al asistente lo decide quien está atendiendo, así que basta
        // con poder contestar.
        Route::post('/panel/whatsapp/reanudar', [WhatsAppController::class, 'resumeBot'])
            ->middleware('can:whatsapp.send')->name('panel.whatsapp.resume');
        // Respuestas rápidas. El permiso existía desde el principio y nunca guardó nada.
        Route::post('/panel/whatsapp/respuestas', [WhatsAppController::class, 'storeTemplate'])
            ->middleware('can:whatsapp.templates.manage')->name('panel.whatsapp.templates.store');
        Route::delete('/panel/whatsapp/respuestas/{template}', [WhatsAppController::class, 'destroyTemplate'])
            ->middleware('can:whatsapp.templates.manage')->name('panel.whatsapp.templates.destroy');
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

/*
 * La cotización que ve el cliente (sin sesión: el cliente no es usuario del sistema).
 *
 * Autentica la FIRMA de la URL, igual que el portal del cliente: `signed` comprueba el HMAC y la
 * caducidad, así que un id cambiado a mano no abre la cotización de otro. Caduca porque un chat se
 * reenvía, y también porque el precio de dentro caduca.
 *
 * La ruta del PDF existe aparte y firmada por su cuenta porque es la que se le pasa a WhatsApp: el
 * servidor del proveedor la abre por su cuenta, sin cookies ni sesión de nadie.
 */
Route::get('/cotizacion/{quote}', PublicQuoteController::class)
    ->middleware('signed')->name('quotes.public');
Route::get('/cotizacion/{quote}/pdf', [PublicQuoteController::class, 'pdf'])
    ->middleware('signed')->name('quotes.public.pdf');

// Webhook entrante de Evolution API (sin sesión; protegido por secreto compartido).
Route::post('/webhooks/evolution', EvolutionWebhookController::class)->name('webhooks.evolution');

// Webhook entrante de Polar: pagos, altas y bajas de suscripción (sin sesión; protegido por firma
// HMAC sobre el cuerpo, que es lo único que impide que alguien se active un plan con un POST).
Route::post('/webhooks/polar', PolarWebhookController::class)->name('webhooks.polar');

/*
 * Avisos de Zernio: mensajes entrantes de Instagram y Facebook, para la bienvenida automática.
 *
 * El token de la dirección es lo único que dice de qué empresa es el aviso; el cuerpo no se cree
 * para eso. La exención de CSRF ya la cubre el patrón `webhooks/*` de bootstrap/app.php.
 */
Route::post('/webhooks/redes/{token}', ZernioWebhookController::class)->name('webhooks.social');

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
