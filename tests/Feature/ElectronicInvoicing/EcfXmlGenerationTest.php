<?php

declare(strict_types=1);

use App\Modules\ElectronicInvoicing\Application\EcfXmlGenerator;
use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfLine;
use App\Modules\ElectronicInvoicing\Domain\EcfParty;
use App\Modules\ElectronicInvoicing\Domain\EcfReference;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use App\Modules\ElectronicInvoicing\Xml\EcfXmlBuilder;
use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use App\Modules\ElectronicInvoicing\Xml\XmlBuildException;
use App\Modules\ElectronicInvoicing\Xml\XmlValidator;
use App\Modules\ElectronicInvoicing\Xml\XsdTree;
use Illuminate\Support\Carbon;

/*
 * Fase 2: del documento canónico al XML, validado contra el XSD OFICIAL de la DGII.
 *
 * Cada caso «válido» pasa por `schemaValidate` contra el esquema descargado de la DGII (con el
 * marcador en el hueco de la firma): si el XML no cumple la estructura oficial, la prueba falla.
 * RNC 131000002 y cédula 00100000009 pasan el dígito verificador del proyecto (Billing\Support\TaxId).
 */

function ecfEmisor(): EcfParty
{
    return new EcfParty(taxId: '131000002', legalName: 'Colmado La Esquina SRL', tradeName: 'La Esquina', address: 'Calle Duarte 45, Santiago');
}

function ecfDocumento(EcfType $tipo, array $extra = []): EcfDocument
{
    return new EcfDocument(...array_merge([
        'type' => $tipo,
        'encf' => $tipo->prefix().'0000000001',
        'issueDate' => Carbon::create(2026, 10, 3),
        'emitter' => ecfEmisor(),
        'lines' => [new EcfLine('Refresco 2L', '2', '118.00', BillingIndicator::Itbis1)],
        'sequenceExpiresAt' => Carbon::create(2027, 12, 31),
    ], $extra));
}

it('el lector del XSD entiende los 15 esquemas oficiales', function (): void {
    $registro = app(SchemaRegistry::class);
    $lector = new XsdTree;

    foreach (array_keys($registro->integrity()) as $archivo) {
        $raiz = $lector->root($registro->path($archivo));
        expect($raiz->children)->not->toBeEmpty("{$archivo} sin elementos");
    }

    expect($lector->root($registro->pathForType(EcfType::Consumo))->name)->toBe('ECF');
});

it('los 15 esquemas compilan para validar (los 3 defectuosos, con sus erratas documentadas)', function (): void {
    $registro = app(SchemaRegistry::class);

    foreach (array_keys($registro->integrity()) as $archivo) {
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new DOMDocument;
        $doc->loadXML('<SinRaizValida/>');
        $doc->schemaValidate($registro->validationPath($archivo));

        // Que el documento vacío no sea válido es lo esperado; lo que NO debe haber es un error del
        // propio esquema («Invalid Schema», tipos sin definir, expresiones que no compilan).
        $deEsquema = array_filter(libxml_get_errors(), fn ($e): bool => ! str_contains($e->message, 'No matching global declaration'));
        libxml_clear_errors();

        expect($deEsquema)->toBe([], "{$archivo}: ".implode(' ', array_map(fn ($e) => trim($e->message), $deEsquema)));
    }

    // Los archivos oficiales siguen intactos: la errata vive en una copia aparte.
    expect($registro->allIntact())->toBeTrue()
        ->and($registro->validationPath('ecf-31.xsd'))->not->toBe($registro->path('ecf-31.xsd'))
        ->and($registro->validationPath('ecf-32.xsd'))->toBe($registro->path('ecf-32.xsd'));
});

it('si la DGII corrige un esquema, la errata obsoleta se detecta en vez de aplicarse a ciegas', function (): void {
    config(['ecf.schema_errata' => array_merge(config('ecf.schema_errata'), ['ecf-32.xsd' => [[
        'type' => 'copy_simple_type', 'name' => 'IndicadorServicioTodoIncluidoType', 'from' => 'ecf-33.xsd', 'reason' => 'prueba',
    ]]])]);

    expect(fn () => app(SchemaRegistry::class)->validationPath('ecf-32.xsd'))
        ->toThrow(RuntimeException::class, 'ya no aplica');
});

it('factura de consumo (32) a cliente sin identificar: válida contra el XSD oficial', function (): void {
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::Consumo));

    expect($r->errors)->toBe([])->and($r->isValid())->toBeTrue();

    $xml = $r->xml->saveXML();
    expect($xml)->toContain('<TipoeCF>32</TipoeCF>')
        ->toContain('<eNCF>E320000000001</eNCF>')
        ->toContain('<IndicadorMontoGravado>1</IndicadorMontoGravado>')
        ->toContain('<Comprador/>')
        ->toContain('<MontoGravadoI1>200.00</MontoGravadoI1>')
        ->toContain('<TotalITBIS1>36.00</TotalITBIS1>')
        ->toContain('<MontoTotal>236.00</MontoTotal>')
        ->toContain('<FechaEmision>03-10-2026</FechaEmision>')
        // El 32 no lleva vencimiento de secuencia en su esquema.
        ->not->toContain('FechaVencimientoSecuencia');

    expect($r->schemaDate)->toBe('2025-10-16');
});

it('factura de consumo con exento, otra tasa y descuento por línea', function (): void {
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::Consumo, ['lines' => [
        new EcfLine('Arroz 5 lb', '1', '150.00', BillingIndicator::Exento),
        new EcfLine('Yogurt', '1', '116.00', BillingIndicator::Itbis2),
        new EcfLine('Detergente', '2', '59.00', BillingIndicator::Itbis1, discount: '18.00'),
        new EcfLine('Delivery', '1', '100.00', BillingIndicator::Itbis1, isService: true),
    ], 'paymentForms' => [['form' => 1, 'amount' => '466.00']]]));

    expect($r->errors)->toBe([]);

    $xml = $r->xml->saveXML();
    expect($xml)->toContain('<MontoExento>150.00</MontoExento>')
        ->toContain('<ITBIS2>16</ITBIS2>')
        ->toContain('<DescuentoMonto>18.00</DescuentoMonto>')
        ->toContain('<TipoSubDescuento>$</TipoSubDescuento>')
        ->toContain('<IndicadorBienoServicio>2</IndicadorBienoServicio>')
        ->toContain('<FormaPago>1</FormaPago>');
});

it('crédito fiscal (31) con comprador: válida y lleva el vencimiento de la secuencia', function (): void {
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::CreditoFiscal, [
        'buyer' => new EcfParty(taxId: '101000007', legalName: 'Ferretería Central SRL'),
    ]));

    expect($r->errors)->toBe([]);
    expect($r->xml->saveXML())->toContain('<FechaVencimientoSecuencia>31-12-2027</FechaVencimientoSecuencia>')
        ->toContain('<RNCComprador>101000007</RNCComprador>')
        ->toContain('<RazonSocialComprador>Ferretería Central SRL</RazonSocialComprador>');
});

it('crédito fiscal sin RNC del comprador: no se genera y lo explica', function (): void {
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::CreditoFiscal));

    expect($r->xml)->toBeNull();
    expect(collect($r->errors)->pluck('message')->implode(' | '))
        ->toContain('necesita el RNC o la cédula del comprador')
        ->toContain('necesita la razón social del comprador');
});

it('un RNC con el dígito verificador mal se rechaza con un mensaje claro', function (): void {
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::CreditoFiscal, [
        'buyer' => new EcfParty(taxId: '131000001', legalName: 'X SRL'),
    ]));

    expect(collect($r->errors)->pluck('message')->all())->toContain('El RNC del comprador no tiene un formato válido.');
});

it('un e-NCF de otro tipo no se acepta', function (): void {
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::Consumo, ['encf' => 'E310000000001']));

    expect(collect($r->errors)->pluck('message')->implode(' '))->toContain('no corresponde al tipo de comprobante E32');
});

it('si el total del documento de origen no cuadra con el detalle, no se genera', function (): void {
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::Consumo, ['declaredTotal' => '300.00']));

    expect($r->xml)->toBeNull()
        ->and(collect($r->errors)->pluck('message')->implode(' '))->toContain('no coincide con el detalle');

    // El céntimo del redondeo oficial sí se admite (ver TaxEngine).
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::Consumo, [
        'lines' => [new EcfLine('Algo', '1', '100.00', BillingIndicator::Itbis1)], 'declaredTotal' => '100.00',
    ]));
    expect($r->errors)->toBe([]);
});

it('cantidades con más de 2 decimales se rechazan en vez de redondearse a escondidas', function (): void {
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::Consumo, [
        'lines' => [new EcfLine('Queso', '1.255', '200.00', BillingIndicator::Itbis1)],
    ]));

    expect(collect($r->errors)->pluck('message')->implode(' '))->toContain('como máximo 2 decimales');
});

it('nota de crédito (34) dentro de 30 días: indicador 0 y referencia al comprobante; válida', function (): void {
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::NotaCredito, [
        'sequenceExpiresAt' => null,
        'reference' => new EcfReference('E310000000007', Carbon::create(2026, 9, 20), code: 3, reason: 'Devolución de 2 unidades'),
    ]));

    expect($r->errors)->toBe([]);
    expect($r->xml->saveXML())->toContain('<IndicadorNotaCredito>0</IndicadorNotaCredito>')
        ->toContain('<NCFModificado>E310000000007</NCFModificado>')
        ->toContain('<FechaNCFModificado>20-09-2026</FechaNCFModificado>')
        ->toContain('<CodigoModificacion>3</CodigoModificacion>')
        ->not->toContain('FechaVencimientoSecuencia');
});

it('nota de crédito emitida pasados 30 días: indicador 1', function (): void {
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::NotaCredito, [
        'reference' => new EcfReference('E310000000007', Carbon::create(2026, 8, 1), code: 1),
    ]));

    expect($r->errors)->toBe([])
        ->and($r->xml->saveXML())->toContain('<IndicadorNotaCredito>1</IndicadorNotaCredito>');
});

it('nota de débito (33) con referencia a un NCF en papel: válida', function (): void {
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::NotaDebito, [
        'reference' => new EcfReference('B0100000045', Carbon::create(2026, 9, 1), code: 3),
    ]));

    expect($r->errors)->toBe([])
        ->and($r->xml->saveXML())->toContain('<NCFModificado>B0100000045</NCFModificado>');
});

it('una nota sin comprobante de referencia, o que supera el total afectado, no se genera', function (): void {
    $sin = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::NotaCredito));
    expect(collect($sin->errors)->pluck('message')->implode(' '))->toContain('necesita el comprobante que modifica');

    $excede = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::NotaCredito, [
        'reference' => new EcfReference('E310000000007', Carbon::create(2026, 9, 20), code: 3, modifiedTotal: '300.00', alreadyCredited: '100.00'),
    ]));
    // 236 de esta nota + 100 ya acreditados > 300 del comprobante original.
    expect(collect($excede->errors)->pluck('message')->implode(' '))->toContain('superarían el total del comprobante');
});

it('un nombre de producto con «<» y «&» no inyecta etiquetas', function (): void {
    $nombre = 'Galletas <b>"Oreo"</b> & Co';
    $r = app(EcfXmlGenerator::class)->generate(ecfDocumento(EcfType::Consumo, [
        'lines' => [new EcfLine($nombre, '1', '59.00', BillingIndicator::Itbis1)],
    ]));

    expect($r->errors)->toBe([]);
    expect($r->xml->saveXML())->toContain('Galletas &lt;b&gt;"Oreo"&lt;/b&gt; &amp; Co')
        ->and($r->xml->getElementsByTagName('NombreItem')->item(0)->textContent)->toBe($nombre);
});

it('un campo que el esquema oficial no tiene es un error, no se descarta en silencio', function (): void {
    expect(fn () => app(EcfXmlBuilder::class)->build(EcfType::Consumo, [
        'Encabezado' => ['Version' => '1.0', 'CampoInventado' => 'x'],
    ]))->toThrow(XmlBuildException::class, 'ECF.Encabezado.CampoInventado');
});

it('los errores del XSD se explican en español', function (): void {
    $registro = app(SchemaRegistry::class);
    $xml = app(EcfXmlBuilder::class)->build(EcfType::Consumo, [
        'Encabezado' => [
            'Version' => '1.0',
            'IdDoc' => ['TipoeCF' => 32, 'eNCF' => 'E320000000001', 'TipoIngresos' => '09', 'TipoPago' => 1],
            'Emisor' => ['RNCEmisor' => '131000002', 'RazonSocialEmisor' => 'X', 'DireccionEmisor' => 'Y', 'FechaEmision' => '2026-10-03'],
            'Comprador' => [],
            'Totales' => ['MontoTotal' => '10.00'],
        ],
        'DetallesItems' => ['Item' => [[
            'NumeroLinea' => 1, 'IndicadorFacturacion' => 1, 'NombreItem' => 'A', 'IndicadorBienoServicio' => 1,
            'CantidadItem' => '1', 'PrecioUnitarioItem' => '10.00', 'MontoItem' => '10.00',
        ]]],
    ]);

    $mensajes = collect(app(XmlValidator::class)->validateUnsigned($xml, $registro->pathForType(EcfType::Consumo)))->pluck('message')->implode(' | ');

    expect($mensajes)->toContain('El valor «09» no está permitido en «TipoIngresos».')
        ->toContain('«FechaEmision» no tiene el formato que exige la DGII');
});
