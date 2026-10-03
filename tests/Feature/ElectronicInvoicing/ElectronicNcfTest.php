<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use App\Modules\ElectronicInvoicing\Ncf\ElectronicNcfException;
use App\Modules\ElectronicInvoicing\Ncf\ElectronicNcfService;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * e-NCF: formato oficial, sin duplicados ni mezclas entre empresas o ambientes, y la reutilización
 * de un número solo cuando la DGII lo permite (`secuenciaUtilizada = false`).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado'));
    $this->company->forceFill(['modules' => null])->save();
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@colmado.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->ncf = app(ElectronicNcfService::class);

    $this->secuencia = fn (array $extra = []) => ElectronicNcfSequence::create(array_merge([
        'company_id' => $this->company->id, 'environment' => Environment::Pruebas, 'ecf_type' => EcfType::Consumo,
        'range_from' => 1, 'range_to' => 3, 'next_number' => 1, 'is_active' => true,
    ], $extra));
});

it('el e-NCF tiene 13 posiciones: E + tipo + 10 dígitos', function (): void {
    ($this->secuencia)();

    $r = $this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo);

    expect($r['encf'])->toBe('E320000000001')->and(strlen($r['encf']))->toBe(13)->and($r['reused'])->toBeFalse();
    expect($this->ncf->parse('E310000000123'))->toBe(['type' => EcfType::CreditoFiscal, 'number' => 123]);
});

it('entrega números consecutivos y avisa claro al agotarse el rango', function (): void {
    ($this->secuencia)();

    $numeros = collect(range(1, 3))->map(fn () => $this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo)['encf']);

    expect($numeros->all())->toBe(['E320000000001', 'E320000000002', 'E320000000003']);
    expect(fn () => $this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo))
        ->toThrow(ElectronicNcfException::class, 'Se agotaron');
});

it('sin secuencia o con la secuencia vencida, no entrega número', function (): void {
    expect(fn () => $this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo))
        ->toThrow(ElectronicNcfException::class, 'No hay una secuencia');

    ($this->secuencia)(['expires_at' => now()->subDay()]);

    expect(fn () => $this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo))
        ->toThrow(ElectronicNcfException::class, 'vencida');
});

it('un número de pruebas nunca sale de una secuencia de producción, ni al revés', function (): void {
    ($this->secuencia)(['environment' => Environment::Produccion, 'range_from' => 500, 'range_to' => 600, 'next_number' => 500]);

    expect(fn () => $this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo))
        ->toThrow(ElectronicNcfException::class);
    expect($this->ncf->allocate($this->company->id, Environment::Produccion, EcfType::Consumo)['encf'])->toBe('E320000000500');
});

it('una empresa nunca consume la secuencia de otra', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ferretería'));
    ($this->secuencia)(['company_id' => $otra->id]);

    expect(fn () => $this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo))
        ->toThrow(ElectronicNcfException::class);
});

it('agota la secuencia más antigua antes de abrir la nueva', function (): void {
    ($this->secuencia)(['range_from' => 1, 'range_to' => 1, 'next_number' => 1]);
    ($this->secuencia)(['range_from' => 100, 'range_to' => 200, 'next_number' => 100]);

    expect($this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo)['encf'])->toBe('E320000000001')
        ->and($this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo)['encf'])->toBe('E320000000100');
});

it('un número liberado por la DGII se reutiliza una sola vez y antes que uno nuevo', function (): void {
    ($this->secuencia)(['range_to' => 10]);
    $primero = $this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo)['encf'];
    $this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo);

    $this->ncf->release($this->company->id, Environment::Pruebas, $primero, 'Rechazado por estructura; secuenciaUtilizada=false');
    // Liberarlo dos veces no lo duplica.
    $this->ncf->release($this->company->id, Environment::Pruebas, $primero, 'repetido');

    $reusado = $this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo);
    $siguiente = $this->ncf->allocate($this->company->id, Environment::Pruebas, EcfType::Consumo);

    expect($reusado['encf'])->toBe($primero)->and($reusado['reused'])->toBeTrue()
        ->and($siguiente['encf'])->toBe('E320000000003')->and($siguiente['reused'])->toBeFalse();
});

it('no se puede liberar un número que nunca se entregó ni uno ajeno', function (): void {
    ($this->secuencia)(['range_to' => 10]);

    expect(fn () => $this->ncf->release($this->company->id, Environment::Pruebas, 'E320000000005', 'x'))
        ->toThrow(ElectronicNcfException::class);
    expect(fn () => $this->ncf->release($this->company->id, Environment::Pruebas, 'B0200000001', 'x'))
        ->toThrow(ElectronicNcfException::class, 'no es un e-NCF válido');
});

it('el dueño registra un rango autorizado desde la pantalla', function (): void {
    $this->actingAs($this->owner)->post(route('panel.e-invoicing.sequences.store'), [
        'environment' => 'pruebas', 'ecf_type' => 31, 'range_from' => 1, 'range_to' => 1000,
    ])->assertSessionHas('panel_ok');

    $s = ElectronicNcfSequence::first();
    expect($s->ecf_type)->toBe(EcfType::CreditoFiscal)->and($s->next_number)->toBe(1)->and($s->expires_at)->toBeNull();

    $this->actingAs($this->owner)->get(route('panel.e-invoicing'))
        ->assertOk()->assertSee('E310000000001')->assertSee('No vence');
});

it('rechaza un rango que se cruza con otro del mismo tipo y ambiente', function (): void {
    ($this->secuencia)(['ecf_type' => EcfType::CreditoFiscal, 'range_from' => 1, 'range_to' => 100]);

    $this->actingAs($this->owner)->post(route('panel.e-invoicing.sequences.store'), [
        'environment' => 'pruebas', 'ecf_type' => 31, 'range_from' => 50, 'range_to' => 150,
    ])->assertSessionHasErrors('range_from');

    // Mismo rango en OTRO ambiente sí se acepta: son numeraciones distintas.
    $this->actingAs($this->owner)->post(route('panel.e-invoicing.sequences.store'), [
        'environment' => 'produccion', 'ecf_type' => 31, 'range_from' => 50, 'range_to' => 150,
    ])->assertSessionHasNoErrors();
});

it('el cajero no registra secuencias', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero', 'email' => 'cajero@colmado.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->post(route('panel.e-invoicing.sequences.store'), [
        'environment' => 'pruebas', 'ecf_type' => 31, 'range_from' => 1, 'range_to' => 10,
    ])->assertForbidden();
});

it('el indicador de ITBIS del producto nace en 18 % y se puede marcar exento', function (): void {
    $p = Product::create(['sku' => 'ARR-1', 'name' => 'Arroz', 'cost' => '30', 'price' => '45']);
    expect($p->fresh()->itbis_indicator)->toBe(1);

    $this->actingAs($this->owner)->put(route('panel.products.update', $p), [
        'sku' => 'ARR-1', 'name' => 'Arroz', 'cost' => '30', 'price' => '45', 'itbis_indicator' => 4,
    ])->assertSessionHasNoErrors();

    expect($p->fresh()->itbis_indicator)->toBe(4);

    $this->actingAs($this->owner)->put(route('panel.products.update', $p), [
        'sku' => 'ARR-1', 'name' => 'Arroz', 'cost' => '30', 'price' => '45', 'itbis_indicator' => 9,
    ])->assertSessionHasErrors('itbis_indicator');
});

it('el selector de ITBIS del producto solo aparece con el módulo de facturación electrónica', function (): void {
    $this->actingAs($this->owner)->get(route('panel.products'))
        ->assertOk()->assertSee('ITBIS en la factura electrónica')->assertSee('Exento');

    // Sin el módulo, la serie B no lee el indicador: enseñarlo haría creer que cambia el ticket.
    $this->company->forceFill(['modules' => ['inventory', 'billing']])->save();

    $this->actingAs($this->owner)->get(route('panel.products'))
        ->assertOk()->assertDontSee('ITBIS en la factura electrónica');
});

it('sin la columna en la base, guardar un producto no revienta', function (): void {
    $p = Product::create(['sku' => 'ARR-2', 'name' => 'Arroz', 'cost' => '30', 'price' => '45']);

    Schema::table('products', fn ($t) => $t->dropColumn('itbis_indicator'));
    DbTable::olvidar();

    $this->actingAs($this->owner)->put(route('panel.products.update', $p), [
        'sku' => 'ARR-2', 'name' => 'Arroz integral', 'cost' => '30', 'price' => '45', 'itbis_indicator' => 4,
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($p->fresh()->name)->toBe('Arroz integral');
});
