<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Exceptions\CustomerPortalException;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Services\CustomerPortalService;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Exceptions\CustomerNotInCompanyException;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Completa una venta para el cliente dado (o sin identificar si es null).
 *
 * El precio es un parámetro porque el código de venta NO sirve para distinguir empresas: es
 * secuencial por empresa, así que la primera venta de cualquiera de ellas es «V-000001». Para
 * comprobar que no se filtran documentos ajenos hace falta un importe distinto.
 */
function saleFor(?Customer $customer, string $sku = 'P1', string $price = '50'): Sale
{
    $warehouse = test()->company->warehouses()->where('is_default', true)->firstOrFail();
    $product = Product::create(['sku' => $sku, 'name' => 'Producto Portal', 'cost' => '10', 'price' => $price]);
    app(StockService::class)->increase($product, $warehouse, StockMovementType::Purchase, '10');

    return app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $warehouse->id,
        lines: [new SaleLineData(productId: $product->id, quantity: '1', unitPrice: $price)],
        paid: $price,
        customerId: $customer?->id,
    ));
}

beforeEach(function (): void {
    Queue::fake(); // El envío por WhatsApp va en cola; aquí interesa el registro, no la entrega.

    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Portal Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->customer = Customer::create([
        'name' => 'Ana Cliente', 'phone' => '18095551234', 'email' => 'ana@cliente.test',
    ]);

    $this->portal = app(CustomerPortalService::class);
});

// ---------------------------------------------------------------- Enlace firmado (acceso)

it('el enlace firmado abre el portal del cliente', function (): void {
    $this->get($this->portal->linkFor($this->customer))
        ->assertOk()
        ->assertSee('Ana Cliente')
        ->assertSee('Portal Co');
});

it('sin firma no se entra', function (): void {
    $this->get(route('portal.customer', $this->customer))->assertForbidden();
});

it('un enlace manipulado para ver otro cliente no es válido', function (): void {
    $otro = Customer::create(['name' => 'Otro Cliente', 'phone' => '18095559999']);

    // Se cambia el id en una URL firmada para otro cliente: la firma deja de cuadrar.
    $manipulado = str_replace(
        "/portal/cliente/{$this->customer->id}?",
        "/portal/cliente/{$otro->id}?",
        $this->portal->linkFor($this->customer),
    );

    $this->get($manipulado)->assertForbidden();
});

it('un enlace caducado deja de servir', function (): void {
    $link = $this->portal->linkFor($this->customer, ttlDays: 7);

    Carbon::setTestNow(Carbon::now()->addDays(8));

    $this->get($link)->assertForbidden();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------- Aislamiento

it('el cliente solo ve sus propios documentos, no los de otro cliente', function (): void {
    $mia = saleFor($this->customer, 'A1');
    $ajena = saleFor(Customer::create(['name' => 'Pedro Otro']), 'A2');

    $this->get($this->portal->linkFor($this->customer))
        ->assertOk()
        ->assertSee($mia->code)
        ->assertDontSee($ajena->code);
});

it('el portal no filtra documentos de otra empresa', function (): void {
    $mia = saleFor($this->customer, 'B1');
    $propia = app(CurrentCompany::class)->id();

    // Otra empresa, con su propio cliente y su propia venta, por un importe reconocible.
    $otraEmpresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena SRL'));
    app(CurrentCompany::class)->set($otraEmpresa->id);
    $this->company = $otraEmpresa; // saleFor usa el almacén de la empresa activa
    $ajena = saleFor(Customer::create(['name' => 'Cliente Ajeno']), 'B2', '77.77');

    app(CurrentCompany::class)->set($propia);

    $this->get($this->portal->linkFor($this->customer))
        ->assertOk()
        ->assertSee($mia->code)
        ->assertDontSee('77.77')
        ->assertDontSee('Cliente Ajeno');

    expect($ajena->company_id)->toBe($otraEmpresa->id);
});

it('un usuario con sesión de otra empresa igual ve el portal correcto', function (): void {
    // Regresión: si el tenant saliera de la sesión (y no del enlace), este cliente «no existiría»
    // para el usuario de otra empresa y el portal respondería 404.
    $mia = saleFor($this->customer, 'C1');

    $otraEmpresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tercera SRL'));
    $intruso = withRole(User::create([
        'company_id' => $otraEmpresa->id, 'name' => 'Intruso',
        'email' => 'intruso@tercera.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($intruso)
        ->get($this->portal->linkFor($this->customer))
        ->assertOk()
        ->assertSee($mia->code);
});

// ---------------------------------------------------------------- Estado de la cuenta y módulos

it('el portal se apaga si la empresa está suspendida', function (): void {
    $this->company->update(['is_active' => false]);

    $this->get($this->portal->linkFor($this->customer))->assertForbidden();
});

it('el portal se apaga si la suscripción no está al día', function (): void {
    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-portal', 'price' => '3500',
        'billing_cycle' => 'monthly', 'trial_days' => 0, 'modules' => null, 'is_active' => true,
    ]);
    $service = app(SubscriptionService::class);
    $service->suspend($service->subscribe($this->company, $plan));

    $this->get($this->portal->linkFor($this->customer))->assertForbidden();
});

it('no muestra las facturas si la empresa no tiene contratado el módulo', function (): void {
    $sale = saleFor($this->customer, 'D1');

    FiscalSequence::create([
        'type' => NcfType::Consumo, 'next_number' => 1,
        'range_from' => 1, 'range_to' => 1000, 'is_active' => true,
    ]);
    $invoice = app(InvoiceService::class)->issueForSale($sale, NcfType::Consumo);

    // La empresa se queda sin facturación (pero conserva ventas).
    $this->company->update(['modules' => ['sales', 'crm', 'whatsapp']]);

    $this->get($this->portal->linkFor($this->customer))
        ->assertOk()
        ->assertSee($sale->code)
        ->assertDontSee($invoice->ncf);
});

// ---------------------------------------------------------------- Enlace de la venta al cliente

it('la venta queda enlazada al cliente y la factura lo hereda', function (): void {
    $sale = saleFor($this->customer, 'E1');

    FiscalSequence::create([
        'type' => NcfType::Consumo, 'next_number' => 1,
        'range_from' => 1, 'range_to' => 1000, 'is_active' => true,
    ]);
    $invoice = app(InvoiceService::class)->issueForSale($sale, NcfType::Consumo);

    expect($sale->customer_id)->toBe($this->customer->id)
        // El nombre del recibo se copia del cliente cuando no se escribe uno a mano.
        ->and($sale->customer_name)->toBe('Ana Cliente')
        ->and($invoice->customer_id)->toBe($this->customer->id);
});

it('la entrega hereda el cliente de la venta que la origina', function (): void {
    $sale = saleFor($this->customer, 'F1');

    $delivery = app(DeliveryService::class)->create('Calle Portal #1', sale: $sale);

    expect($delivery->customer_id)->toBe($this->customer->id);
});

it('la venta de mostrador sigue sin cliente', function (): void {
    $sale = saleFor(null, 'G1');

    expect($sale->customer_id)->toBeNull();
});

it('no se puede enlazar una venta con un cliente de otra empresa', function (): void {
    $otraEmpresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'Cuarta SRL'));
    app(CurrentCompany::class)->set($otraEmpresa->id);
    $ajeno = Customer::create(['name' => 'Cliente Ajeno']);
    app(CurrentCompany::class)->set($this->company->id);

    expect(fn () => saleFor($ajeno, 'H1'))->toThrow(CustomerNotInCompanyException::class);
});

// ---------------------------------------------------------------- Pantallas del panel

it('la pantalla del CRM ofrece enviar el enlace del portal', function (): void {
    $owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@portal.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($owner)->get(route('panel.customers'))
        ->assertOk()
        ->assertSee(route('panel.customers.portal', $this->customer));
});

it('no ofrece el enlace si la empresa no tiene WhatsApp contratado', function (): void {
    $this->company->update(['modules' => ['crm']]); // sin whatsapp

    $owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner2@portal.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($owner)->get(route('panel.customers'))
        ->assertOk()
        ->assertDontSee(route('panel.customers.portal', $this->customer));
});

it('el POS permite identificar al cliente al cobrar', function (): void {
    app(CashService::class)->open(CashRegister::create(['name' => 'Caja 1']), '0');

    $owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@portal.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($owner)->get(route('panel.pos'))
        ->assertOk()
        ->assertSee('name="customer_id"', false)
        ->assertSee('Ana Cliente');
});

it('un usuario sin permiso de gestionar clientes no puede enviar el enlace', function (): void {
    // Sin rol: ningún permiso. Los tres roles del catálogo (owner/admin/staff) sí tienen
    // «customers.manage» —un cajero registra clientes—, así que el que prueba la puerta es este.
    $sinRol = User::create([
        'company_id' => $this->company->id, 'name' => 'Sin Rol',
        'email' => 'sinrol@portal.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($sinRol)
        ->post(route('panel.customers.portal', $this->customer))
        ->assertForbidden();
});

// ---------------------------------------------------------------- Envío del enlace

it('envía el enlace por WhatsApp y lo registra como mensaje saliente', function (): void {
    $this->portal->sendLink($this->customer);

    $message = WaMessage::query()->latest('id')->firstOrFail();

    expect($message->body)->toContain('/portal/cliente/'.$this->customer->id)
        ->and($message->body)->toContain('Ana Cliente');
});

it('no envía el enlace a un cliente sin teléfono', function (): void {
    $sinTelefono = Customer::create(['name' => 'Sin Teléfono']);

    expect(fn () => $this->portal->sendLink($sinTelefono))
        ->toThrow(CustomerPortalException::class);
});
