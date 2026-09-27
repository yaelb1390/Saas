<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Personalización de documentos (colores, pie, condiciones, mostrar/ocultar), desde Mi Empresa.
 *
 * Vive en `settings.documents.*`, con la misma convención que `features.*`. Lo que importa: una
 * empresa sin configurar nada sigue viendo la paleta BMIA por defecto, y guardar/desmarcar un
 * interruptor se refleja igual que ya pasa con "¿Qué usa tu negocio?".
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Mi Negocio'));
    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@negocio.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);
});

it('sin configurar nada, los documentos usan la paleta BMIA por defecto', function (): void {
    expect($this->company->documentSetting('primary_color'))->toBe('#1677FF')
        ->and($this->company->documentSetting('secondary_color'))->toBe('#0B3D91')
        ->and($this->company->documentSetting('show_signature'))->toBeTrue()
        ->and($this->company->documentSetting('show_tax_breakdown'))->toBeTrue()
        ->and($this->company->documentSetting('show_discount'))->toBeTrue();
});

it('guardar el formulario de empresa persiste los colores y textos del documento', function (): void {
    $this->actingAs($this->owner)->put(route('panel.company-profile.update'), [
        'name' => $this->company->name,
        'documents' => [
            'primary_color' => '#ff0000',
            'secondary_color' => '#00ff00',
            'footer_text' => 'Gracias por su compra',
            'terms_text' => 'Precios sujetos a cambio',
            'show_signature' => '1',
            'show_tax_breakdown' => '1',
            'show_discount' => '1',
        ],
    ])->assertRedirect();

    $fresh = $this->company->fresh();

    expect($fresh->documentSetting('primary_color'))->toBe('#ff0000')
        ->and($fresh->documentSetting('secondary_color'))->toBe('#00ff00')
        ->and($fresh->documentSetting('footer_text'))->toBe('Gracias por su compra')
        ->and($fresh->documentSetting('terms_text'))->toBe('Precios sujetos a cambio');
});

it('desmarcar un interruptor lo apaga: las casillas no marcadas no llegan en la petición', function (): void {
    // Primero se enciende explícitamente...
    $this->actingAs($this->owner)->put(route('panel.company-profile.update'), [
        'name' => $this->company->name,
        'documents' => ['show_signature' => '1'],
    ]);

    expect($this->company->fresh()->documentSetting('show_signature'))->toBeTrue();

    // ...y al guardar de nuevo SIN esa casilla marcada, debe quedar apagado y no seguir encendido.
    $this->actingAs($this->owner)->put(route('panel.company-profile.update'), [
        'name' => $this->company->name,
        'documents' => [],
    ])->assertRedirect();

    expect($this->company->fresh()->documentSetting('show_signature'))->toBeFalse();
});

it('un color con formato inválido se rechaza', function (): void {
    $this->actingAs($this->owner)->put(route('panel.company-profile.update'), [
        'name' => $this->company->name,
        'documents' => ['primary_color' => 'no-es-un-color'],
    ])->assertSessionHasErrors('documents.primary_color');
});

it('el pie y las condiciones personalizados salen en el PDF de la cotización', function (): void {
    $this->company->forceFill([
        'settings' => ['documents' => ['footer_text' => 'Pie de prueba único', 'terms_text' => 'Condición de prueba única']],
    ])->save();

    $producto = \App\Modules\Inventory\Models\Product::create(['sku' => 'X1', 'name' => 'Prod', 'price' => '50']);
    $quote = app(\App\Modules\Quotes\Services\QuoteService::class)->crear(
        [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '50']],
        ['customer_name' => 'Cliente'],
    )->load('items', 'user');

    $html = view('quotes.pdf', ['quote' => $quote, 'company' => $this->company->fresh(), 'logo' => null])->render();

    expect($html)->toContain('Pie de prueba único')
        ->and($html)->toContain('Condición de prueba única');
});

it('el color elegido por la empresa se pinta de verdad en el PDF, no solo el default', function (): void {
    $this->company->forceFill([
        'settings' => ['documents' => ['primary_color' => '#22C55E', 'secondary_color' => '#166534']],
    ])->save();

    $producto = \App\Modules\Inventory\Models\Product::create(['sku' => 'X2', 'name' => 'Prod', 'price' => '50']);
    $quote = app(\App\Modules\Quotes\Services\QuoteService::class)->crear(
        [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '50']],
        ['customer_name' => 'Cliente'],
    )->load('items', 'user');

    $html = view('quotes.pdf', ['quote' => $quote, 'company' => $this->company->fresh(), 'logo' => null])->render();

    expect($html)->toContain('#22C55E')
        ->and($html)->toContain('#166534')
        // Y el default ya NO debe seguir ahí: si apareciera igual, el color elegido no se estaría
        // leyendo de verdad, o se estarían pintando los dos a la vez.
        ->and($html)->not->toContain('#1677FF')
        ->and($html)->not->toContain('#0B3D91');
});
