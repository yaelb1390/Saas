<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\CompanyLogoStore;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\OptionGroup;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\POS\Services\CheckoutService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Los datos de la empresa en sus recibos.
 *
 * Las columnas —dirección, teléfono, razón social, logo— existían desde el principio y el recibo ya
 * las imprimía si estaban rellenas. Lo que no existía era la pantalla para rellenarlas, así que en la
 * práctica todos los recibos del sistema salían con el nombre y poco más.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');

    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado Uno'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@colmado.test', 'password' => 'secret-password',
    ]), 'owner');
});

/** Un PNG diminuto de verdad, para que la validación de imagen lo acepte. */
function logoDePrueba(string $nombre = 'logo.png'): UploadedFile
{
    return UploadedFile::fake()->image($nombre, 300, 120);
}

// --------------------------------------------------------------------------- Guardar los datos

it('el dueño guarda dirección, teléfono y razón social', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)->put(route('panel.company-profile.update'), [
        'name' => 'Colmado Uno',
        'legal_name' => 'Colmado Uno, SRL',
        'tax_id' => '131000001',
        'phone' => '(809) 555-0000',
        'address' => 'Calle Duarte 45, Santiago',
    ])->assertRedirect();

    $empresa = $this->company->fresh();

    expect($empresa->legal_name)->toBe('Colmado Uno, SRL')
        ->and($empresa->phone)->toBe('(809) 555-0000')
        ->and($empresa->address)->toBe('Calle Duarte 45, Santiago');
});

it('la razón social manda sobre el nombre comercial en los documentos', function (): void {
    // En un recibo lo que vale es el nombre registrado; el comercial es el del rótulo.
    $this->company->update(['legal_name' => 'Colmado Uno, SRL']);
    expect($this->company->fresh()->nombreParaDocumentos())->toBe('Colmado Uno, SRL');

    $this->company->update(['legal_name' => null]);
    expect($this->company->fresh()->nombreParaDocumentos())->toBe('Colmado Uno');
});

it('solo el nombre es obligatorio', function (): void {
    // Una empresa recién dada de alta tiene que poder vender sin haber rellenado su RNC.
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->put(route('panel.company-profile.update'), ['name' => ''])
        ->assertSessionHasErrors('name');

    $this->actingAs($this->owner)
        ->put(route('panel.company-profile.update'), ['name' => 'Solo el nombre'])
        ->assertRedirect()->assertSessionHasNoErrors();
});

// ---------------------------------------------------------------------------------- El logo

it('sube el logo y lo deja guardado en el disco', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)->put(route('panel.company-profile.update'), [
        'name' => 'Colmado Uno',
        'logo' => logoDePrueba(),
    ])->assertRedirect();

    $empresa = $this->company->fresh();

    expect($empresa->hasLogo())->toBeTrue()
        ->and(CompanyLogoStore::disk()->exists((string) $empresa->logo_path))->toBeTrue();
});

it('cambiar el logo borra el anterior', function (): void {
    // Sin esto, cada cambio dejaría un archivo huérfano en el almacén para siempre.
    $this->withoutVite();
    $store = app(CompanyLogoStore::class);

    $store->store($this->company, logoDePrueba('viejo.png'));
    $viejo = (string) $this->company->fresh()->logo_path;

    $store->store($this->company->fresh(), logoDePrueba('nuevo.png'));
    $nuevo = (string) $this->company->fresh()->logo_path;

    expect($nuevo)->not->toBe($viejo)
        ->and(CompanyLogoStore::disk()->exists($viejo))->toBeFalse()
        ->and(CompanyLogoStore::disk()->exists($nuevo))->toBeTrue();
});

it('quitar el logo lo borra del disco y de la empresa', function (): void {
    $this->withoutVite();
    app(CompanyLogoStore::class)->store($this->company, logoDePrueba());
    $ruta = (string) $this->company->fresh()->logo_path;

    $this->actingAs($this->owner)->delete(route('panel.company-profile.logo.destroy'))->assertRedirect();

    expect($this->company->fresh()->hasLogo())->toBeFalse()
        ->and(CompanyLogoStore::disk()->exists($ruta))->toBeFalse();
});

it('rechaza lo que no es una imagen', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)->put(route('panel.company-profile.update'), [
        'name' => 'Colmado Uno',
        'logo' => UploadedFile::fake()->create('contrato.pdf', 40, 'application/pdf'),
    ])->assertSessionHasErrors('logo');
});

it('el logo se sirve por su ruta y da 404 si no hay', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)->get(route('panel.company.logo'))->assertNotFound();

    app(CompanyLogoStore::class)->store($this->company, logoDePrueba());

    $this->actingAs($this->owner)->get(route('panel.company.logo'))->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

// ------------------------------------------------------------------- Salen en los documentos

/**
 * Cobra una venta de `$lineas` artículos distintos y devuelve su id.
 *
 * El número de líneas importa: el alto del papel del recibo crece con ellas, así que un recibo de una
 * línea no demuestra nada sobre uno de cinco, que es el que se ve en un mostrador de verdad.
 */
function ventaParaRecibo(int $lineas = 1): int
{
    $almacen = Warehouse::firstOrFail();
    $caja = app(CashService::class)->open(
        CashRegister::firstOrCreate(['name' => 'Caja 1']), '0', test()->owner->id,
    );

    $items = [];

    for ($i = 1; $i <= $lineas; $i++) {
        // Nombres largos y con acentos: es lo que ocupa dos renglones y hace crecer el recibo.
        $producto = Product::create([
            'name' => "Batida de guineo con leche número {$i}",
            'price' => '150', 'sku' => "B-{$i}", 'track_stock' => true,
        ]);
        app(StockService::class)->increase($producto, $almacen, StockMovementType::Initial, '10');

        $items[] = new SaleLineData(productId: (int) $producto->id, quantity: '2', unitPrice: '150');
    }

    return app(CheckoutService::class)->checkout($caja, new CreateSaleData(
        warehouseId: (int) $almacen->id,
        lines: $items,
        paymentMethod: PaymentMethod::Cash,
    ))->id;
}

it('el recibo imprime la dirección, el teléfono y el logo', function (): void {
    // Es todo el objetivo de la pantalla: que el papel que se lleva el cliente diga quién eres y
    // cómo volver a encontrarte.
    $this->withoutVite();

    $this->company->update([
        'legal_name' => 'Colmado Uno, SRL',
        'tax_id' => '131000001',
        'phone' => '(809) 555-0000',
        'address' => 'Calle Duarte 45, Santiago',
    ]);
    app(CompanyLogoStore::class)->store($this->company->fresh(), logoDePrueba());

    $html = $this->actingAs($this->owner)
        ->get(route('panel.sales.receipt', ventaParaRecibo()))
        ->assertOk()->getContent();

    expect($html)->toContain('Colmado Uno, SRL')
        ->toContain('131000001')
        ->toContain('Calle Duarte 45, Santiago')
        ->toContain('(809) 555-0000')
        ->toContain(route('panel.company.logo'));
});

it('sin datos rellenos el recibo sale igual, solo con el nombre', function (): void {
    // El sistema tiene que seguir cobrando el primer día, antes de que nadie configure nada.
    $this->withoutVite();

    $html = $this->actingAs($this->owner)
        ->get(route('panel.sales.receipt', ventaParaRecibo()))
        ->assertOk()->getContent();

    expect($html)->toContain('Colmado Uno')
        ->and($html)->not->toContain('Tel:');
});

it('sin logo, la cabecera se queda con el nombre del negocio', function (): void {
    /*
     * El caso normal —la gran mayoría de clientes no sube logo— y el que no puede fallar: un recibo
     * sin nada que identifique al negocio no le sirve al cliente para reclamar ni para volver.
     *
     * Se comprueba también que NO se pinta una imagen rota: sin logo no debe salir ningún <img> de
     * la cabecera, que es lo que ocurriría si la plantilla lo pintara sin preguntar.
     */
    $this->withoutVite();
    $this->company->update(['legal_name' => 'Colmado Uno, SRL', 'phone' => '(809) 555-0000']);

    expect($this->company->fresh()->hasLogo())->toBeFalse();

    $html = $this->actingAs($this->owner)
        ->get(route('panel.sales.receipt', ventaParaRecibo()))
        ->assertOk()->getContent();

    expect($html)->toContain('Colmado Uno, SRL')
        ->toContain('(809) 555-0000')
        ->and($html)->not->toContain(route('panel.company.logo'));
});

it('con logo salen el logo Y el nombre, no uno en lugar del otro', function (): void {
    // En un recibo el nombre no sobra nunca: es lo que exige un comprobante y lo que queda legible
    // aunque la impresora térmica convierta el logo en una mancha gris.
    $this->withoutVite();
    $this->company->update(['legal_name' => 'Colmado Uno, SRL']);
    app(CompanyLogoStore::class)->store($this->company->fresh(), logoDePrueba());

    $html = $this->actingAs($this->owner)
        ->get(route('panel.sales.receipt', ventaParaRecibo()))
        ->assertOk()->getContent();

    expect($html)->toContain(route('panel.company.logo'))
        ->toContain('Colmado Uno, SRL');
});

it('en el PDF el logo viaja incrustado, no como enlace', function (): void {
    // dompdf no lleva las cookies del usuario: una URL protegida le devolvería un 404 y el recibo
    // impreso saldría sin logo justo cuando más se nota.
    app(CompanyLogoStore::class)->store($this->company, logoDePrueba());

    $uri = CompanyLogoStore::dataUri($this->company->fresh());

    expect($uri)->toStartWith('data:image/png;base64,')
        ->and(strlen((string) $uri))->toBeGreaterThan(100);
});

it('sin logo, el PDF no intenta incrustar nada', function (): void {
    expect(CompanyLogoStore::dataUri($this->company))->toBeNull()
        ->and(CompanyLogoStore::dataUri(null))->toBeNull();
});

it('el PDF del recibo se genera con el logo puesto', function (): void {
    // La comprobación que de verdad importa: que el documento SALGA. Un logo ilegible es un
    // problema; un PDF que revienta deja al cajero sin poder entregar nada.
    $this->withoutVite();
    app(CompanyLogoStore::class)->store($this->company, logoDePrueba());
    $this->company->update(['address' => 'Calle Duarte 45']);

    $this->actingAs($this->owner)
        ->get(route('panel.sales.receipt.pdf', ventaParaRecibo()))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('el recibo cabe en UNA sola página, con logo y sin él', function (): void {
    /*
     * En un rollo térmico, «dos páginas» es papel cortado por la mitad: el cliente se lleva el
     * ticket sin el total y sin el «gracias por su compra».
     *
     * El alto del papel se calcula sumando cabecera, líneas y pie. Al añadir el logo, ese cálculo no
     * lo conocía y un ticket de cinco artículos empezó a salir en dos hojas. Aquí se comprueba que el
     * papel crece cuando hay logo, que es lo que lo evita.
     */
    $this->withoutVite();

    // Se cuentan los objetos `/Type /Page` del documento: es lo que de verdad se imprime, y lo que
    // ningún test miraba —solo comprobaban que respondiera 200—.
    $paginas = function (int $ventaId): int {
        $pdf = (string) $this->actingAs($this->owner)
            ->get(route('panel.sales.receipt.pdf', $ventaId))
            ->assertOk()->getContent();

        return (int) preg_match_all('/\/Type\s*\/Page[^s]/', $pdf);
    };

    // Cinco líneas con nombres largos: el recibo del mostrador, no el caso fácil de una línea.
    $venta = Sale::findOrFail(ventaParaRecibo(5));

    expect($paginas($venta->id))->toBe(1, 'El recibo de 5 líneas SIN logo sale en más de una página.');

    app(CompanyLogoStore::class)->store($this->company, logoDePrueba());

    expect($paginas($venta->id))->toBe(1, 'El recibo de 5 líneas CON logo sale en más de una página: falta reservarle su alto.')
        // El hueco reservado tiene que cubrir de verdad al logo, no quedarse corto.
        ->and(CompanyLogoStore::PDF_ESPACIO_PT)->toBeGreaterThan(CompanyLogoStore::PDF_ALTO_PT);

    $this->actingAs($this->owner)
        ->get(route('panel.sales.receipt.pdf', $venta->id))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('el recibo en PDF pesa lo que debe pesar', function (): void {
    /*
     * dompdf incrusta la fuente ENTERA si no se activa el recorte, y así cada recibo de cuatro
     * líneas pesaba 858 KB: casi todo DejaVu Sans. Se manda por WhatsApp y se imprime en el
     * mostrador, muchas veces con datos móviles.
     *
     * El tope es generoso a propósito —no se trata de vigilar kilobytes—, pero salta si alguien
     * desactiva `enable_font_subsetting` en config/dompdf.php y volvemos al mega por ticket.
     */
    $this->withoutVite();
    app(CompanyLogoStore::class)->store($this->company, logoDePrueba());

    $pdf = $this->actingAs($this->owner)
        ->get(route('panel.sales.receipt.pdf', ventaParaRecibo()))
        ->assertOk()->getContent();

    $kb = strlen((string) $pdf) / 1024;

    expect($kb)->toBeLessThan(
        200.0,
        sprintf('El recibo pesa %d KB. ¿Se desactivó enable_font_subsetting en config/dompdf.php?', (int) $kb),
    );
});

// ------------------------------------------------------------------- Funciones opcionales

it('el cliente enciende y apaga «Tamaños y sabores» él mismo', function (): void {
    // No es un módulo: el módulo lo decide el plan y lo vende el operador. Esto es lo que el negocio
    // elige USAR de lo que ya tiene, y tiene que poder cambiarlo sin pedir permiso a nadie.
    $this->withoutVite();

    expect($this->company->usesFeature('option_groups'))->toBeFalse();

    $this->actingAs($this->owner)->put(route('panel.company-profile.update'), [
        'name' => 'Colmado Uno',
        'features' => ['option_groups' => '1'],
    ])->assertRedirect();

    expect($this->company->fresh()->usesFeature('option_groups'))->toBeTrue();
});

it('desmarcar la casilla la apaga', function (): void {
    /*
     * Una casilla sin marcar NO se envía en el formulario, así que si se leyera solo lo que llega
     * nunca podría apagarse: quedaría encendida para siempre y el cliente pensaría que la pantalla
     * está rota. Por eso se recorre el catálogo entero y lo ausente se guarda como apagado.
     */
    $this->withoutVite();
    $this->company->update(['settings' => ['features' => ['option_groups' => true]]]);

    $this->actingAs($this->owner)
        ->put(route('panel.company-profile.update'), ['name' => 'Colmado Uno'])
        ->assertRedirect();

    expect($this->company->fresh()->usesFeature('option_groups'))->toBeFalse();
});

it('apagar la función no borra los grupos ya creados', function (): void {
    // «Apagar» es esconder, no destruir: quien la vuelva a encender tiene que encontrarlo todo donde
    // lo dejó. Borrar datos del cliente al desmarcar una casilla sería imperdonable.
    $this->withoutVite();
    $this->company->update(['settings' => ['features' => ['option_groups' => true]]]);

    $grupo = OptionGroup::create([
        'name' => 'Tamaño', 'min_select' => 1, 'max_select' => 1, 'is_active' => true,
    ]);

    $this->actingAs($this->owner)
        ->put(route('panel.company-profile.update'), ['name' => 'Colmado Uno'])
        ->assertRedirect();

    expect($this->company->fresh()->usesFeature('option_groups'))->toBeFalse()
        ->and(OptionGroup::find($grupo->id))->not->toBeNull();
});

it('la pantalla no anida un formulario dentro de otro', function (): void {
    /*
     * Un `<form>` dentro de otro es HTML inválido: el navegador desmonta el interior y de paso rompe
     * el envío del exterior. Pasó aquí —el botón «Quitar el logo» usa el componente de confirmación,
     * que pinta su propio formulario— y el efecto fue que las casillas de «¿Qué usa tu negocio?» no
     * llegaban al servidor. El interruptor parecía no funcionar.
     *
     * Ningún test por HTTP lo habría visto: al mandar la petición directamente, los campos van igual.
     * Solo se nota en un navegador de verdad, y por eso se comprueba la ESTRUCTURA del HTML.
     */
    $this->withoutVite();
    app(CompanyLogoStore::class)->store($this->company, logoDePrueba());

    $html = $this->actingAs($this->owner)->get(route('panel.company-profile'))->assertOk()->getContent();

    // Se recorre el HTML contando aperturas y cierres: si alguna vez hay dos formularios abiertos a
    // la vez, están anidados.
    preg_match_all('/<\/?form\b/i', (string) $html, $marcas, PREG_OFFSET_CAPTURE);

    $abiertos = 0;
    $anidados = false;

    foreach ($marcas[0] as [$etiqueta]) {
        if (str_starts_with($etiqueta, '</')) {
            $abiertos--;

            continue;
        }

        $abiertos++;

        if ($abiertos > 1) {
            $anidados = true;
        }
    }

    expect($anidados)->toBeFalse('Hay un <form> dentro de otro: el navegador romperá el envío del exterior.');
});

it('guardar los datos de la empresa no toca el resto de ajustes', function (): void {
    // En `settings` viven también el perfil del punto de venta y lo que venga después. Pisar el
    // array entero al guardar una dirección se llevaría por delante la configuración del POS.
    $this->withoutVite();
    $this->company->update(['settings' => ['pos' => ['profile' => 'repuestos', 'options' => ['serial' => true]]]]);

    $this->actingAs($this->owner)
        ->put(route('panel.company-profile.update'), ['name' => 'Colmado Uno', 'address' => 'Calle 1'])
        ->assertRedirect();

    expect(data_get($this->company->fresh()->settings, 'pos.profile'))->toBe('repuestos')
        ->and(data_get($this->company->fresh()->settings, 'pos.options.serial'))->toBeTrue();
});

// ------------------------------------------------------------------ Permisos y aislamiento

it('un cajero no puede tocar los datos de la empresa', function (): void {
    $this->withoutVite();

    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@colmado.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.company-profile'))->assertForbidden();
    $this->actingAs($cajero)->put(route('panel.company-profile.update'), ['name' => 'Otro'])->assertForbidden();
});

it('el cajero SÍ ve el logo, porque lo lleva el recibo que imprime', function (): void {
    $this->withoutVite();
    app(CompanyLogoStore::class)->store($this->company, logoDePrueba());

    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero Dos',
        'email' => 'cajero2@colmado.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.company.logo'))->assertOk();
});

it('cada empresa ve su propio logo', function (): void {
    $this->withoutVite();
    app(CompanyLogoStore::class)->store($this->company, logoDePrueba('uno.png'));
    $rutaUno = (string) $this->company->fresh()->logo_path;

    app(CurrentCompany::class)->forget();
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado Dos'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Otro', 'email' => 'otro@dos.test', 'password' => 'secret-password',
    ]), 'owner');

    // La otra empresa no tiene logo, así que su ruta da 404 aunque exista el fichero de la primera.
    $this->actingAs($ajeno)->get(route('panel.company.logo'))->assertNotFound();

    expect(Company::withoutGlobalScopes()->find($otra->id)->logo_path)->toBeNull()
        ->and(CompanyLogoStore::disk()->exists($rutaUno))->toBeTrue();
});
