<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\AI\Services\RagService;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\RoleProvisioner;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\DTOs\CreateCustomerData;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\CRM\Services\CrmService;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\HR\DTOs\CreateEmployeeData;
use App\Modules\HR\Services\HrService;
use App\Modules\Inventory\DTOs\CreateProductData;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\POS\Services\CheckoutService;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\WhatsApp\Services\WhatsAppService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Asegura el catálogo de permisos globales.
        app(RoleProvisioner::class)->ensurePermissions();

        // Super administrador de plataforma (sin empresa, opera a nivel global).
        User::query()->updateOrCreate(
            ['email' => 'superadmin@bmbusiness.os'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'is_super_admin' => true,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        // Planes de suscripción de arranque (configurables después desde el panel de plataforma).
        Plan::query()->updateOrCreate(['slug' => 'basico'], [
            'name' => 'Básico', 'description' => 'Lo esencial para vender y controlar stock.',
            'price' => '1500.00', 'billing_cycle' => 'monthly', 'trial_days' => 14,
            'modules' => ['pos', 'inventory', 'sales', 'reports'], 'max_users' => 3, 'is_active' => true,
        ]);
        Plan::query()->updateOrCreate(['slug' => 'pro'], [
            'name' => 'Pro', 'description' => 'Para negocios en crecimiento: CRM, facturación y WhatsApp.',
            'price' => '3500.00', 'billing_cycle' => 'monthly', 'trial_days' => 14,
            'modules' => ['pos', 'inventory', 'sales', 'purchasing', 'crm', 'whatsapp', 'billing', 'finance', 'loans', 'reports'],
            'max_users' => 10, 'is_active' => true,
        ]);
        Plan::query()->updateOrCreate(['slug' => 'empresarial'], [
            'name' => 'Empresarial', 'description' => 'Todos los módulos, incluida la IA.',
            'price' => '7000.00', 'billing_cycle' => 'monthly', 'trial_days' => 0,
            'modules' => null, 'max_users' => null, 'is_active' => true,
        ]);

        // Empresa demo. Al crearse dispara CompanyCreated → provisiona roles por empresa.
        $company = app(CompanyService::class)->create(new CreateCompanyData(
            name: 'BM Demo Company',
            legalName: 'BM Demo, SRL',
            taxId: '131000001',
            email: 'demo@bmbusiness.os',
            currency: 'DOP',
        ));

        // Usuario propietario de la empresa demo con rol 'owner' en el contexto de esa empresa.
        $owner = User::query()->updateOrCreate(
            ['email' => 'owner@bmbusiness.os'],
            [
                'company_id' => $company->id,
                'name' => 'Owner Demo',
                'password' => Hash::make('password'),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $owner->assignRole('owner');

        // Cajero de demo (rol 'staff'): opera el POS y factura, pero no puede anular comprobantes,
        // tocar el inventario ni ver RRHH o Finanzas. Sirve para comprobar los permisos en vivo.
        $cajero = User::query()->updateOrCreate(
            ['email' => 'cajero@bmbusiness.os'],
            [
                'company_id' => $company->id,
                'name' => 'Cajero Demo',
                'password' => Hash::make('password'),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
        $cajero->assignRole('staff');

        // --- Datos demo de Fase 2 (Inventario + Compras) ---
        // Activamos el tenant para que los modelos hereden el company_id del contexto.
        app(CurrentCompany::class)->set($company->id);

        $warehouse = $company->warehouses()->where('is_default', true)->firstOrFail();

        $category = Category::create(['name' => 'General', 'is_active' => true]);

        $product = app(ProductService::class)->create(
            new CreateProductData(
                sku: 'PROD-0001',
                name: 'Producto Demo',
                categoryId: $category->id,
                unit: 'unidad',
                cost: '100.00',
                price: '150.00',
            ),
            initialWarehouse: $warehouse,
            initialQuantity: '25',
        );

        Supplier::create([
            'name' => 'Proveedor Demo',
            'tax_id' => '101000001',
            'email' => 'proveedor@demo.os',
            'is_active' => true,
        ]);

        // Caja + una venta de POS de ejemplo (descuenta stock y cobra en caja).
        $register = CashRegister::create(['name' => 'Caja Principal', 'code' => 'CAJA-01']);
        $session = app(CashService::class)->open($register, '1000');

        $sale = app(CheckoutService::class)->checkout($session, new CreateSaleData(
            warehouseId: $warehouse->id,
            lines: [new SaleLineData(productId: $product->id, quantity: '2', unitPrice: '150.00')],
            paid: '300.00',
            customerName: 'Cliente Demo',
        ));

        // Secuencias fiscales autorizadas (con fecha límite de emisión, como las concede la DGII).
        FiscalSequence::create([
            'type' => NcfType::Consumo,
            'next_number' => 1,
            'range_from' => 1,
            'range_to' => 1000,
            'number_length' => 8,
            'expires_at' => now()->addYear(),
            'is_active' => true,
        ]);

        FiscalSequence::create([
            'type' => NcfType::CreditoFiscal,
            'next_number' => 1,
            'range_from' => 1,
            'range_to' => 500,
            'number_length' => 8,
            'expires_at' => now()->addYear(),
            'is_active' => true,
        ]);

        app(InvoiceService::class)->issueForSale($sale, NcfType::Consumo);

        // --- Datos demo de Fase 5 (CRM + WhatsApp) ---
        $customer = app(CrmService::class)->createCustomer(new CreateCustomerData(
            name: 'Cliente Demo',
            email: 'cliente@demo.os',
            phone: '18095551234',
        ));

        $pipeline = Pipeline::where('is_default', true)->firstOrFail();
        $opportunity = app(CrmService::class)->openOpportunity(
            $pipeline,
            'Oportunidad Demo',
            '5000.00',
            $customer,
        );

        $contacted = $pipeline->stages()->where('name', 'Contactado')->firstOrFail();
        app(CrmService::class)->moveToStage($opportunity, $contacted);

        // Mensaje de WhatsApp (gateway de log si Evolution no está configurado). Enlaza al
        // cliente por el teléfono coincidente.
        app(WhatsAppService::class)->sendText('18095551234', 'Hola, gracias por tu compra en BM Demo Company.');

        // --- Datos demo de Fase 6 (IA + RAG) ---
        // Indexa un documento en la base de conocimiento (RAG).
        app(RagService::class)->index(
            'Política de devoluciones',
            'Aceptamos devoluciones dentro de los 30 días posteriores a la compra presentando la '
            .'factura con su NCF. El reembolso se procesa en un plazo de 3 días hábiles al mismo '
            .'método de pago. Los productos deben estar en su empaque original y sin uso.',
            source: 'manual-interno',
        );

        // Un mensaje entrante dispara automáticamente el análisis de sentimiento (IA).
        app(WhatsAppService::class)->recordInbound('18095551234', '¡Gracias, excelente servicio, me encanta!');

        // --- Datos demo de Fase 7 (Delivery + RRHH) ---
        // La venta demo ya registró su ingreso en "Caja General" vía el listener de Finanzas.
        $delivery = app(DeliveryService::class)->create(
            'Calle Demo #1, Santo Domingo',
            sale: $sale,
        );
        app(DeliveryService::class)->assign($delivery, 'Repartidor Demo');

        // Empleado vinculado al usuario owner (para el portal del empleado) + una entrada.
        $employee = app(HrService::class)->hire(new CreateEmployeeData(
            name: 'Owner Demo',
            position: 'Gerente General',
            salary: '75000.00',
            userId: $owner->id,
        ));
        app(HrService::class)->clockIn($employee);

        app(CurrentCompany::class)->forget();
    }
}
