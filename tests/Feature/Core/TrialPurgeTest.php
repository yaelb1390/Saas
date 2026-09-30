<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => app(CurrentCompany::class)->forget());

/**
 * Crea una empresa con una suscripción de prueba (o del estado dado) y su marca de purga.
 */
function trialCompany(string $name, ?Carbon $purgeAt, SubscriptionStatus $status = SubscriptionStatus::Trialing): Company
{
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: $name));

    Subscription::create([
        'company_id' => $company->id,
        'plan_id' => null,
        'status' => $status,
        'trial_ends_at' => Carbon::now()->subDays(2),
        'purge_at' => $purgeAt,
    ]);

    return $company;
}

/**
 * Da de alta un cliente dentro de la empresa indicada (usa el tenant activo).
 */
function seedCustomer(Company $company, string $name): void
{
    app(CurrentCompany::class)->set($company->id);
    Customer::create(['name' => $name]);
    app(CurrentCompany::class)->forget();
}

it('purga los datos de una prueba vencida y conserva la cuenta', function (): void {
    $company = trialCompany('Vencida', Carbon::now()->subHour());

    app(CurrentCompany::class)->set($company->id);
    Product::create(['sku' => 'P1', 'name' => 'Prod', 'cost' => '10', 'price' => '20']);
    Customer::create(['name' => 'Cliente Prueba']);
    app(CurrentCompany::class)->forget();

    Artisan::call('trials:purge');

    // Datos de negocio borrados...
    expect(DB::table('products')->where('company_id', $company->id)->count())->toBe(0)
        ->and(DB::table('customers')->where('company_id', $company->id)->count())->toBe(0);

    // ...pero la cuenta (empresa + sucursal) se conserva y no se vuelve a purgar.
    expect(Company::find($company->id))->not->toBeNull()
        ->and(DB::table('branches')->where('company_id', $company->id)->count())->toBeGreaterThan(0)
        ->and(Subscription::where('company_id', $company->id)->first()->purge_at)->toBeNull();
});

/**
 * Cazado por TenantPurgeCompletenessTest: 13 tablas con `company_id` no estaban en TABLES ni en
 * KEPT (4 de Cuentas por Cobrar/Pagar, 9 de Social Commerce). Ya se clasificaron ahí; esto prueba
 * que el ORDEN elegido de verdad respeta las claves foráneas `restrict` contra Postgres, no solo
 * que la lista esté completa. `receivable_payments`/`payable_payments` y `social_commerce_rules`
 * apuntan con `restrict` a `accounts` y a `products` respectivamente.
 */
it('purga las cuentas por cobrar/pagar y Social Commerce sin romper una FK restrict', function (): void {
    $company = trialCompany('Con Cxc Cxp y Social Commerce', Carbon::now()->subHour());
    $companyId = $company->id;
    $ahora = Carbon::now();

    app(CurrentCompany::class)->set($companyId);
    $product = Product::create(['sku' => 'P1', 'name' => 'Prod', 'cost' => '10', 'price' => '20']);
    app(CurrentCompany::class)->forget();

    $accountId = DB::table('accounts')->insertGetId([
        'company_id' => $companyId, 'name' => 'Caja', 'type' => 'cash',
        'created_at' => $ahora, 'updated_at' => $ahora,
    ]);

    // Cuenta por cobrar + su abono (restrict contra accounts).
    $receivableId = DB::table('receivables')->insertGetId([
        'company_id' => $companyId, 'code' => 'CXC-1', 'total' => 100, 'balance' => 0,
        'created_at' => $ahora, 'updated_at' => $ahora,
    ]);
    DB::table('receivable_payments')->insert([
        'company_id' => $companyId, 'receivable_id' => $receivableId, 'account_id' => $accountId,
        'amount' => 100, 'balance_after' => 0, 'paid_at' => $ahora,
        'created_at' => $ahora, 'updated_at' => $ahora,
    ]);

    // Cuenta por pagar + su pago (restrict contra accounts).
    $payableId = DB::table('payables')->insertGetId([
        'company_id' => $companyId, 'code' => 'CXP-1', 'total' => 50, 'balance' => 0,
        'created_at' => $ahora, 'updated_at' => $ahora,
    ]);
    DB::table('payable_payments')->insert([
        'company_id' => $companyId, 'payable_id' => $payableId, 'account_id' => $accountId,
        'amount' => 50, 'balance_after' => 0, 'paid_at' => $ahora,
        'created_at' => $ahora, 'updated_at' => $ahora,
    ]);

    // Regla de Social Commerce (restrict contra products) + su plantilla.
    $ruleId = DB::table('social_commerce_rules')->insertGetId([
        'company_id' => $companyId, 'name' => 'Precio', 'zernio_account_id' => 'acc_1',
        'product_id' => $product->id, 'keywords' => json_encode(['precio']),
        'created_at' => $ahora, 'updated_at' => $ahora,
    ]);
    DB::table('social_commerce_rule_templates')->insert([
        'company_id' => $companyId, 'rule_id' => $ruleId, 'channel' => 'dm', 'body' => 'Cuesta {precio}',
        'created_at' => $ahora, 'updated_at' => $ahora,
    ]);

    // Contacto → conversación → mensaje.
    $contactId = DB::table('social_commerce_contact_identities')->insertGetId([
        'company_id' => $companyId, 'channel' => 'instagram', 'external_id' => 'ig_123',
        'first_seen_at' => $ahora, 'last_seen_at' => $ahora,
        'created_at' => $ahora, 'updated_at' => $ahora,
    ]);
    $conversationId = DB::table('social_commerce_conversations')->insertGetId([
        'company_id' => $companyId, 'contact_identity_id' => $contactId,
        'zernio_conversation_id' => 'conv_1', 'zernio_account_id' => 'acc_1',
        'created_at' => $ahora, 'updated_at' => $ahora,
    ]);
    DB::table('social_commerce_messages')->insert([
        'company_id' => $companyId, 'conversation_id' => $conversationId,
        'direction' => 'incoming', 'body' => 'Hola', 'sent_at' => $ahora,
        'created_at' => $ahora, 'updated_at' => $ahora,
    ]);

    Artisan::call('trials:purge');

    foreach ([
        'receivable_payments', 'receivables', 'payable_payments', 'payables', 'accounts',
        'social_commerce_rule_templates', 'social_commerce_rules', 'social_commerce_messages',
        'social_commerce_conversations', 'social_commerce_contact_identities',
    ] as $tabla) {
        expect(DB::table($tabla)->where('company_id', $companyId)->count())->toBe(0, "quedó basura en {$tabla}");
    }
});

it('no purga pruebas con fecha futura ni suscripciones activas', function (): void {
    $futura = trialCompany('Futura', Carbon::now()->addDays(5));
    seedCustomer($futura, 'Cliente Futura');

    // purge_at en el pasado pero ya activa (pagó) → intocable.
    $activa = trialCompany('Activa', Carbon::now()->subHour(), SubscriptionStatus::Active);
    seedCustomer($activa, 'Cliente Activa');

    Artisan::call('trials:purge');

    expect(DB::table('customers')->where('company_id', $futura->id)->count())->toBe(1)
        ->and(DB::table('customers')->where('company_id', $activa->id)->count())->toBe(1);
});

it('purgar una empresa no afecta los datos de otra', function (): void {
    $a = trialCompany('A', Carbon::now()->subHour());       // se purga
    $b = trialCompany('B', null);                            // prueba de operador (sin purga)

    seedCustomer($a, 'De A');
    seedCustomer($b, 'De B');

    Artisan::call('trials:purge');

    expect(DB::table('customers')->where('company_id', $a->id)->count())->toBe(0)
        ->and(DB::table('customers')->where('company_id', $b->id)->count())->toBe(1);
});

it('el endpoint de cron exige el secreto correcto', function (): void {
    config(['services.cron.secret' => 'topsecret']);

    $this->get('/tareas/purgar-pruebas')->assertForbidden();
    $this->withHeader('Authorization', 'Bearer incorrecto')->get('/tareas/purgar-pruebas')->assertForbidden();

    $this->withHeader('Authorization', 'Bearer topsecret')
        ->get('/tareas/purgar-pruebas')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('el endpoint de cron se bloquea si no hay secreto configurado', function (): void {
    config(['services.cron.secret' => null]);

    $this->withHeader('Authorization', 'Bearer loquesea')->get('/tareas/purgar-pruebas')->assertForbidden();
});

/*
 * EL SIMULACRO.
 *
 * Existe porque esta tarea NUNCA se había ejecutado en producción: al encenderla, la primera pasada
 * se encuentra con todas las pruebas vencidas desde el principio y se las lleva de golpe. En
 * serverless no hay consola donde mirar antes cuántas son, así que la forma de verlo es pedírselo a
 * la propia dirección con `?simular=1`.
 */
it('el simulacro dice a quién le borraría los datos, sin borrar nada', function (): void {
    $company = trialCompany('Mirona', Carbon::now()->subHour());
    seedCustomer($company, 'Cliente Que Se Queda');

    Artisan::call('trials:purge', ['--simular' => true]);

    expect(Artisan::output())->toContain('Mirona')
        ->and(DB::table('customers')->where('company_id', $company->id)->count())->toBe(1);
});

/*
 * Y ESTE ES EL QUE DE VERDAD IMPORTA.
 *
 * `purge_at` es la marca que dice «a esta hay que purgarla». Si el simulacro la dejara en NULL —que
 * es lo que hace la purga de verdad al terminar—, la purga siguiente se saltaría justo a quien
 * acabas de mirar, y esa prueba se quedaría con sus datos para siempre sin que nadie lo notara.
 * Mirar no puede cambiar lo que se mira.
 */
it('el simulacro no desmarca la purga, o la de verdad se la saltaría después', function (): void {
    $company = trialCompany('Mirona', Carbon::now()->subHour());
    seedCustomer($company, 'Cliente');

    Artisan::call('trials:purge', ['--simular' => true]);

    expect(Subscription::where('company_id', $company->id)->value('purge_at'))->not->toBeNull();

    // Y la de verdad, después, sí se la lleva.
    Artisan::call('trials:purge');

    expect(DB::table('customers')->where('company_id', $company->id)->count())->toBe(0);
});

it('el endpoint acepta el simulacro, y sin pedirlo borra de verdad', function (): void {
    config(['services.cron.secret' => 'topsecret']);

    $company = trialCompany('Por Endpoint', Carbon::now()->subHour());
    seedCustomer($company, 'Cliente');

    $this->withHeader('Authorization', 'Bearer topsecret')
        ->get('/tareas/purgar-pruebas?simular=1')
        ->assertOk();

    expect(DB::table('customers')->where('company_id', $company->id)->count())->toBe(1);

    // Sin el parámetro es la tarea de siempre: Vercel Cron llama así, sin nada detrás.
    $this->withHeader('Authorization', 'Bearer topsecret')
        ->get('/tareas/purgar-pruebas')
        ->assertOk();

    expect(DB::table('customers')->where('company_id', $company->id)->count())->toBe(0);
});
