<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyEraser;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\TenantDataPurger;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Application\EcfValidationException;
use App\Modules\ElectronicInvoicing\Application\ElectronicInvoiceService;
use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfLine;
use App\Modules\ElectronicInvoicing\Domain\EcfParty;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoiceContingency;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfRelease;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use App\Modules\ElectronicInvoicing\Providers\DgiiDirectProvider;
use App\Modules\ElectronicInvoicing\Providers\ProviderOutcome;
use App\Modules\ElectronicInvoicing\Signature\CertificateException;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Fase 4: el circuito completo de emisión (generar → número → XML → firma → envío → respuesta →
 * estado), con el proveedor de prueba y con el de la DGII contra respuestas simuladas.
 *
 * Nunca se llama a la DGII de verdad: `preventStrayRequests` hace fallar cualquier petición que no
 * esté simulada. El certificado es autofirmado y se genera en cada prueba.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['filesystems.fiscal_documents' => 'local']);
    Storage::fake('local');

    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado'));
    app(CurrentCompany::class)->set($this->company->id);

    $ajustes = ElectronicInvoicingSettings::paraEmpresa($this->company);
    $ajustes->forceFill(['tax_id' => '131000002', 'legal_name' => 'Colmado SRL'])->save();
    $this->ajustes = $ajustes;

    $this->certificado = function (): void {
        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'PRUEBA BMIA', 'countryName' => 'DO'], $llave, ['digest_alg' => 'sha256']);
        openssl_pkcs12_export(openssl_csr_sign($csr, null, $llave, 365, ['digest_alg' => 'sha256']), $p12, $llave, 'clave');
        app(CertificateVault::class)->store($this->company, $p12, 'clave');
    };

    $this->secuencia = fn (EcfType $tipo, array $extra = []) => ElectronicNcfSequence::create(array_merge([
        'company_id' => $this->company->id, 'environment' => Environment::Pruebas, 'ecf_type' => $tipo,
        'range_from' => 1, 'range_to' => 50, 'next_number' => 1, 'is_active' => true,
        'expires_at' => Carbon::create(2027, 12, 31),
    ], $extra));

    $this->documento = fn (EcfType $tipo, array $extra = []) => new EcfDocument(...array_merge([
        'type' => $tipo,
        // Lo sustituye el número que se reserve al emitir.
        'encf' => $tipo->prefix().'0000000000',
        'issueDate' => Carbon::create(2026, 10, 3),
        'emitter' => new EcfParty(taxId: '131000002', legalName: 'Colmado SRL', address: 'Calle 1'),
        'lines' => [new EcfLine('Refresco', '2', '118.00', BillingIndicator::Itbis1)],
        'buyer' => $tipo === EcfType::CreditoFiscal ? new EcfParty(taxId: '101000007', legalName: 'Cliente SRL') : null,
    ], $extra));

    $this->emitir = fn (EcfType $tipo, array $extra = []) => app(ElectronicInvoiceService::class)
        ->issue($this->company, ($this->documento)($tipo, $extra));
});

it('consumo bajo RD$250.000: firma, envía el resumen (RFCE) y queda aceptado', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::Consumo);

    $ecf = ($this->emitir)(EcfType::Consumo);

    expect($ecf->status)->toBe(EcfStatus::Aceptado)
        ->and($ecf->e_ncf)->toBe('E320000000001')
        ->and($ecf->sends_summary)->toBeTrue()
        ->and($ecf->security_code)->toHaveLength(6)
        ->and((string) $ecf->total)->toBe('236.00');

    expect($ecf->files()->pluck('kind')->sort()->values()->all())->toBe(['firmado', 'original', 'rfce', 'rfce_firmado']);
    expect($ecf->responses()->pluck('operation')->all())->toBe(['send_summary']);

    // La bitácora recorre la máquina de estados, paso a paso.
    expect($ecf->auditLogs()->whereNotNull('to_status')->orderBy('id')->pluck('to_status')->all())
        ->toBe(['generado', 'xml_generado', 'firmado', 'pendiente_envio', 'enviando', 'aceptado']);

    // Los archivos se guardan con su huella y en la carpeta de la empresa y el ambiente.
    $firmado = $ecf->file('firmado');
    expect($firmado->path)->toStartWith("ecf/{$this->company->id}/pruebas/2026/10/E320000000001/")
        ->and(hash('sha256', Storage::disk('local')->get($firmado->path)))->toBe($firmado->sha256);
});

it('crédito fiscal: queda recibido con TrackId y la consulta posterior lo da por aceptado', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::CreditoFiscal);

    $ecf = ($this->emitir)(EcfType::CreditoFiscal);

    expect($ecf->status)->toBe(EcfStatus::Recibido)
        ->and($ecf->track_id)->toStartWith('FAKE-')
        ->and($ecf->sends_summary)->toBeFalse();

    // Antes de su próximo intento no se consulta.
    expect(app(ElectronicInvoiceService::class)->processPending())->toBe(['enviados' => 0, 'consultados' => 0, 'restantes' => 0]);

    $this->travel(2)->minutes();
    $r = app(ElectronicInvoiceService::class)->processPending();

    expect($r['consultados'])->toBe(1)
        ->and($ecf->fresh()->status)->toBe(EcfStatus::Aceptado)
        ->and($ecf->responses()->pluck('operation')->all())->toBe(['send', 'query']);
});

it('sin comunicación: queda pendiente, abre contingencia y la cierra al reenviar con éxito', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::CreditoFiscal);
    config(['ecf.fake.send' => 'transient_error']);

    $ecf = ($this->emitir)(EcfType::CreditoFiscal);

    expect($ecf->status)->toBe(EcfStatus::PendienteEnvio)
        ->and($ecf->next_attempt_at)->not->toBeNull()
        ->and($ecf->contingency_id)->not->toBeNull()
        ->and(ElectronicInvoiceContingency::whereNull('ended_at')->count())->toBe(1);

    config(['ecf.fake.send' => 'received']);
    $this->travel(2)->minutes();
    $r = app(ElectronicInvoiceService::class)->processPending();

    expect($r['enviados'])->toBe(1)
        ->and($ecf->fresh()->status)->toBe(EcfStatus::Recibido)
        ->and($ecf->fresh()->attempts)->toBe(2)
        ->and(ElectronicInvoiceContingency::whereNull('ended_at')->count())->toBe(0);
});

it('rechazo con secuenciaUtilizada = false: el número vuelve al uso y lo lleva el documento siguiente', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::CreditoFiscal);
    config(['ecf.fake.send' => 'rejected', 'ecf.fake.sequence_used' => false]);

    $rechazado = ($this->emitir)(EcfType::CreditoFiscal);

    expect($rechazado->status)->toBe(EcfStatus::Rechazado)
        ->and(ElectronicNcfRelease::where('number', 1)->count())->toBe(1);

    config(['ecf.fake.send' => 'received']);
    $nuevo = ($this->emitir)(EcfType::CreditoFiscal);

    // El mismo e-NCF, en otro documento; el rechazado se conserva como historia.
    expect($nuevo->e_ncf)->toBe($rechazado->e_ncf)
        ->and($nuevo->id)->not->toBe($rechazado->id)
        ->and($rechazado->fresh()->status)->toBe(EcfStatus::Rechazado);
});

it('rechazo con la secuencia utilizada: el número queda consumido', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::CreditoFiscal);
    config(['ecf.fake.send' => 'rejected', 'ecf.fake.sequence_used' => true]);

    ($this->emitir)(EcfType::CreditoFiscal);
    config(['ecf.fake.send' => 'received']);

    expect(ElectronicNcfRelease::count())->toBe(0)
        ->and(($this->emitir)(EcfType::CreditoFiscal)->e_ncf)->toBe('E310000000002');
});

it('el mismo e-NCF no puede estar vivo dos veces en una empresa y ambiente', function (): void {
    $fila = fn (string $estado) => DB::table('electronic_invoices')->insert([
        'company_id' => $this->company->id, 'environment' => 'pruebas', 'ecf_type' => 31, 'e_ncf' => 'E310000000009',
        'status' => $estado, 'issue_date' => '2026-10-03', 'total' => 1, 'provider' => 'fake', 'spec_version' => '1.0',
    ]);

    $fila('rechazado');
    $fila('aceptado');

    expect(fn () => $fila('recibido'))->toThrow(Illuminate\Database\QueryException::class);
});

it('un documento inválido no consume número', function (): void {
    ($this->certificado)();
    $secuencia = ($this->secuencia)(EcfType::CreditoFiscal);

    // Crédito fiscal sin comprador: lo exige el XSD.
    expect(fn () => ($this->emitir)(EcfType::CreditoFiscal, ['buyer' => null]))->toThrow(EcfValidationException::class);

    expect($secuencia->fresh()->next_number)->toBe(1)
        ->and(ElectronicInvoice::count())->toBe(0);
});

it('sin certificado no se reserva número', function (): void {
    $secuencia = ($this->secuencia)(EcfType::Consumo);

    expect(fn () => ($this->emitir)(EcfType::Consumo))->toThrow(CertificateException::class);

    expect($secuencia->fresh()->next_number)->toBe(1)
        ->and(ElectronicInvoice::count())->toBe(0);
});

it('el proveedor de prueba se niega en producción: el documento queda en error, no «aceptado»', function (): void {
    ($this->certificado)();
    $this->ajustes->forceFill(['environment' => Environment::Produccion])->save();
    ($this->secuencia)(EcfType::Consumo, ['environment' => Environment::Produccion]);

    $ecf = ($this->emitir)(EcfType::Consumo);

    expect($ecf->status)->toBe(EcfStatus::Error)
        ->and($ecf->last_error)->toContain('no puede usarse en producción');
});

it('las respuestas y la bitácora solo se añaden: no se modifican ni se borran', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::Consumo);
    $ecf = ($this->emitir)(EcfType::Consumo);

    $respuesta = $ecf->responses()->first();
    $log = $ecf->auditLogs()->first();

    expect(fn () => $respuesta->update(['outcome' => 'accepted_conditional']))->toThrow(LogicException::class);
    expect(fn () => $respuesta->delete())->toThrow(LogicException::class);
    expect(fn () => $log->update(['action' => 'otra']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
});

it('la máquina de estados no permite saltos ilegales', function (): void {
    expect(EcfStatus::Borrador->canTransitionTo(EcfStatus::Enviando))->toBeFalse()
        ->and(EcfStatus::Aceptado->canTransitionTo(EcfStatus::PendienteEnvio))->toBeFalse()
        ->and(EcfStatus::Rechazado->canTransitionTo(EcfStatus::Aceptado))->toBeFalse()
        ->and(EcfStatus::Aceptado->isFinal())->toBeTrue()
        ->and(EcfStatus::Enviando->canTransitionTo(EcfStatus::Recibido))->toBeTrue();
});

it('un XML firmado alterado en el disco no se envía: el documento queda en error, no atascado', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::CreditoFiscal);
    config(['ecf.fake.send' => 'transient_error']);
    $ecf = ($this->emitir)(EcfType::CreditoFiscal);

    Storage::disk('local')->put($ecf->file('firmado')->path, '<ECF>alterado</ECF>');
    config(['ecf.fake.send' => 'received']);
    $this->travel(2)->minutes();
    app(ElectronicInvoiceService::class)->processPending();

    expect($ecf->fresh()->status)->toBe(EcfStatus::Error)
        ->and($ecf->fresh()->last_error)->toContain('no coincide con su huella')
        ->and($ecf->responses()->count())->toBe(1);
});

it('un documento aceptado no se reenvía', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::Consumo);
    $ecf = ($this->emitir)(EcfType::Consumo);

    app(ElectronicInvoiceService::class)->send($ecf);

    expect($ecf->fresh()->status)->toBe(EcfStatus::Aceptado)
        ->and($ecf->responses()->count())->toBe(1);
});

it('borrar la empresa conserva sus e-CF y su rastro', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::Consumo);
    $ecf = ($this->emitir)(EcfType::Consumo);

    app(TenantDataPurger::class)->purge($this->company);
    expect(DB::table('electronic_invoices')->where('id', $ecf->id)->exists())->toBeTrue();

    app(CompanyEraser::class)->erase($this->company);

    foreach (['electronic_invoices', 'electronic_invoice_files', 'electronic_invoice_responses', 'electronic_invoice_audit_logs'] as $tabla) {
        expect(DB::table($tabla)->where('company_id', $ecf->company_id)->count())->toBeGreaterThan(0);
    }
});

/*
 * Proveedor de la DGII contra respuestas simuladas con la forma de la documentación [DT].
 */

it('DGII: autentica con la semilla firmada, guarda el token cifrado y recibe el TrackId', function (): void {
    ($this->certificado)();

    Http::fake([
        'ecf.dgii.gov.do/testecf/autenticacion/api/autenticacion/semilla' => Http::response('<?xml version="1.0" encoding="utf-8"?><SemillaModel><valor>abc123</valor><fecha>2026-10-03T10:00:00</fecha></SemillaModel>'),
        'ecf.dgii.gov.do/testecf/autenticacion/api/autenticacion/validarsemilla' => Http::response(['token' => 'tok-secreto', 'expira' => now()->addHour()->toIso8601String(), 'expedido' => now()->toIso8601String()]),
        'ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::response(['trackId' => 'track-1', 'error' => null, 'mensaje' => null]),
    ]);

    $proveedor = app(DgiiDirectProvider::class);
    $r1 = $proveedor->send($this->company, Environment::Pruebas, '<ECF/>', '131000002E310000000001.xml');
    $r2 = $proveedor->send($this->company, Environment::Pruebas, '<ECF/>', '131000002E310000000001.xml');

    expect($r1->outcome)->toBe(ProviderOutcome::Received)->and($r1->trackId)->toBe('track-1')
        ->and($r2->outcome)->toBe(ProviderOutcome::Received);

    // Una sola autenticación para los dos envíos: el token se reutiliza.
    Http::assertSentCount(4);

    // La semilla va firmada y el envío lleva el token.
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/validarsemilla') && str_contains($r->body(), 'SignatureValue'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/facturaselectronicas') && $r->hasHeader('Authorization', 'Bearer tok-secreto'));

    // Nunca sale de pruebas, y el token no queda en claro en la caché.
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/ecf/') || str_contains($r->url(), '/certecf/'));
    expect((string) Cache::get("ecf:dgii-token:{$this->company->id}:pruebas"))->not->toContain('tok-secreto')->not->toBe('');
});

it('DGII: traduce los códigos de la consulta de resultado [DT p.25]', function (): void {
    ($this->certificado)();
    Cache::put("ecf:dgii-token:{$this->company->id}:pruebas", Illuminate\Support\Facades\Crypt::encryptString(json_encode(['token' => 't', 'expira' => now()->addHour()->toIso8601String()])), 3600);

    Http::fake([
        '*/consultaresultado/api/consultas/estado?trackid=a' => Http::response(['codigo' => 1, 'estado' => 'Aceptado', 'mensajes' => []]),
        '*/consultaresultado/api/consultas/estado?trackid=b' => Http::response(['codigo' => 2, 'estado' => 'Rechazado', 'mensajes' => [['codigo' => '1', 'valor' => 'Firma inválida']], 'secuenciaUtilizada' => false]),
        '*/consultaresultado/api/consultas/estado?trackid=c' => Http::response(['codigo' => 3, 'estado' => 'En Proceso']),
        '*/consultaresultado/api/consultas/estado?trackid=d' => Http::response(['codigo' => 4, 'estado' => 'Aceptado Condicional']),
        '*/consultaresultado/api/consultas/estado?trackid=e' => Http::response('Servicio no disponible', 503),
    ]);

    $p = app(DgiiDirectProvider::class);
    $rechazo = $p->queryResult($this->company, Environment::Pruebas, 'b');

    expect($p->queryResult($this->company, Environment::Pruebas, 'a')->outcome)->toBe(ProviderOutcome::Accepted)
        ->and($rechazo->outcome)->toBe(ProviderOutcome::Rejected)
        ->and($rechazo->sequenceUsed)->toBeFalse()
        ->and($rechazo->summary())->toBe('Firma inválida')
        ->and($p->queryResult($this->company, Environment::Pruebas, 'c')->outcome)->toBe(ProviderOutcome::InProcess)
        ->and($p->queryResult($this->company, Environment::Pruebas, 'd')->outcome)->toBe(ProviderOutcome::AcceptedConditional)
        ->and($p->queryResult($this->company, Environment::Pruebas, 'e')->outcome)->toBe(ProviderOutcome::TransientError);
});

it('DGII: rechaza una semilla con DOCTYPE (XXE) sin firmarla', function (): void {
    ($this->certificado)();

    Http::fake([
        '*/semilla' => Http::response('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><SemillaModel><valor>&e;</valor></SemillaModel>'),
    ]);

    $r = app(DgiiDirectProvider::class)->send($this->company, Environment::Pruebas, '<ECF/>', 'x.xml');

    expect($r->outcome)->toBe(ProviderOutcome::PermanentError)->and($r->error)->toContain('DOCTYPE');
    Http::assertNotSent(fn (Request $req) => str_ends_with($req->url(), '/validarsemilla'));
});

it('DGII: sin red es un fallo pasajero (se reintenta), no un rechazo', function (): void {
    ($this->certificado)();
    Http::fake(['*' => fn () => throw new Illuminate\Http\Client\ConnectionException('timeout')]);

    $r = app(DgiiDirectProvider::class)->send($this->company, Environment::Pruebas, '<ECF/>', 'x.xml');

    expect($r->outcome)->toBe(ProviderOutcome::TransientError);
});

it('la tarea /tareas/ecf-procesar exige el secreto del cron', function (): void {
    config(['services.cron.secret' => 'secreto-cron']);

    $this->get('/tareas/ecf-procesar')->assertForbidden();
    $this->get('/tareas/ecf-procesar', ['Authorization' => 'Bearer otro'])->assertForbidden();

    $this->get('/tareas/ecf-procesar', ['Authorization' => 'Bearer secreto-cron'])
        ->assertOk()->assertJson(['ok' => true])->assertJsonPath('output', fn (string $s) => str_contains($s, 'e-CF enviados: 0'));
});

/*
 * Fase 6b: los documentos en pantalla — lista, ficha, descarga verificada y acciones manuales.
 */

it('la lista y la ficha enseñan el documento, sus respuestas y su bitácora', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::CreditoFiscal);
    $ecf = ($this->emitir)(EcfType::CreditoFiscal);

    $this->company->forceFill(['modules' => null])->save();
    $duena = withRole(\App\Models\User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@docs.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($duena)->get(route('panel.e-invoicing.documents'))->assertOk()->assertSee('E310000000001')->assertSee('Recibido');
    $this->actingAs($duena)->get(route('panel.e-invoicing.documents', ['estado' => 'aceptado']))->assertOk()->assertDontSee('E310000000001');

    $this->actingAs($duena)->get(route('panel.e-invoicing.documents.show', $ecf))->assertOk()
        ->assertSee('Respuestas de la DGII')->assertSee('Bitácora')->assertSee('Consultar resultado')->assertSee('firmado');

    $firmado = $ecf->file('firmado');
    $this->actingAs($duena)->get(route('panel.e-invoicing.documents.file', [$ecf, $firmado]))
        ->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8');

    // Alterado en el disco: no se entrega.
    Storage::disk('local')->put($firmado->path, '<ECF/>');
    $this->actingAs($duena)->get(route('panel.e-invoicing.documents.file', [$ecf, $firmado]))->assertStatus(409);

    // Consultar a mano: el proveedor de prueba responde aceptado.
    $this->actingAs($duena)->post(route('panel.e-invoicing.documents.query', $ecf))->assertSessionHas('panel_ok');
    expect($ecf->fresh()->status)->toBe(EcfStatus::Aceptado);
});

it('reintentar a mano un envío pendiente', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::CreditoFiscal);
    config(['ecf.fake.send' => 'transient_error']);
    $ecf = ($this->emitir)(EcfType::CreditoFiscal);
    expect($ecf->status)->toBe(EcfStatus::PendienteEnvio);

    $this->company->forceFill(['modules' => null])->save();
    $duena = withRole(\App\Models\User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@reintento.test', 'password' => 'secret-password',
    ]), 'owner');

    config(['ecf.fake.send' => 'received']);
    $this->actingAs($duena)->post(route('panel.e-invoicing.documents.resend', $ecf))->assertSessionHas('panel_ok');
    expect($ecf->fresh()->status)->toBe(EcfStatus::Recibido);

    // Ya recibido: no se reenvía.
    $this->actingAs($duena)->post(route('panel.e-invoicing.documents.resend', $ecf))->assertSessionHas('panel_error');
});

it('otra empresa no ve ni descarga los documentos ajenos', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::Consumo);
    $ecf = ($this->emitir)(EcfType::Consumo);

    app(CurrentCompany::class)->forget();
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));
    $otra->forceFill(['modules' => null])->save();
    $ajeno = withRole(\App\Models\User::create([
        'company_id' => $otra->id, 'name' => 'Ajeno', 'email' => 'ajeno@docs.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($ajeno)->get(route('panel.e-invoicing.documents.show', $ecf->id))->assertNotFound();
    $this->actingAs($ajeno)->get(route('panel.e-invoicing.documents'))->assertOk()->assertDontSee($ecf->e_ncf);
});

/*
 * Fase 6d: diagnóstico y avisos en la campana.
 */

it('el diagnóstico dice qué falta y cómo solucionarlo', function (): void {
    $this->company->forceFill(['modules' => null])->save();
    $duena = withRole(\App\Models\User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@diag.test', 'password' => 'secret-password',
    ]), 'owner');

    // El montaje no pone dirección: el diagnóstico lo detecta.
    $chequeos = collect(app(\App\Modules\ElectronicInvoicing\Application\Diagnostics::class)->checks($this->company))->keyBy('key');
    expect($chequeos['datos']['level'])->toBe('error')->and($chequeos['datos']['detail'])->toContain('dirección');

    $this->ajustes->forceFill(['address' => 'Calle 1'])->save();
    $chequeos = collect(app(\App\Modules\ElectronicInvoicing\Application\Diagnostics::class)->checks($this->company))->keyBy('key');

    // Sin certificado ni secuencias (emisión apagada: advertencias, no errores).
    expect($chequeos['datos']['level'])->toBe('ok')
        ->and($chequeos['certificado']['level'])->toBe('aviso')
        ->and($chequeos['secuencia_32']['level'])->toBe('aviso')
        ->and($chequeos['secuencia_32']['fix'])->toContain('Secuencias de e-NCF')
        ->and($chequeos['xsd']['level'])->toBe('ok')
        ->and($chequeos['almacenamiento']['level'])->toBe('ok');

    $this->actingAs($duena)->get(route('panel.e-invoicing.diagnostics'))->assertOk()
        ->assertSee('Certificado digital')->assertSee('Cómo solucionarlo');
});

it('un envío interrumpido y un procesador que no corre salen como error y llegan a la campana', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::CreditoFiscal);
    config(['ecf.fake.send' => 'transient_error']);
    $pendiente = ($this->emitir)(EcfType::CreditoFiscal);
    config(['ecf.fake.send' => 'received']);
    $otro = ($this->emitir)(EcfType::CreditoFiscal);

    // El segundo se quedó en «enviando» hace 30 min (la función se cortó a mitad).
    DB::table('electronic_invoices')->where('id', $otro->id)->update(['status' => 'enviando', 'updated_at' => now()->subMinutes(30)]);

    $chequeos = collect(app(\App\Modules\ElectronicInvoicing\Application\Diagnostics::class)->checks($this->company))->keyBy('key');
    expect($chequeos['atascados']['level'])->toBe('error')
        ->and($chequeos['cron']['level'])->toBe('error')
        // El segundo envío funcionó: la contingencia que abrió el primero ya se cerró.
        ->and($chequeos['contingencia']['level'])->toBe('ok');

    $this->company->forceFill(['modules' => null])->save();
    $alerta = collect(app(\App\Modules\Reports\Services\AlertService::class)->compute())->firstWhere('key', 'e_invoicing');
    expect($alerta)->not->toBeNull()
        ->and($alerta['url'])->toBe(route('panel.e-invoicing.diagnostics'))
        // El envío interrumpido.
        ->and($alerta['count'])->toBe(1);

    expect($pendiente->fresh()->status)->toBe(EcfStatus::PendienteEnvio);
});

/*
 * Fases 6e y 6f: cifras de emisión y pasos para emitir.
 */

it('las cifras de 30 días cuentan por estado y por tipo, sin importes de rechazados', function (): void {
    ($this->certificado)();
    ($this->secuencia)(EcfType::Consumo);
    ($this->secuencia)(EcfType::CreditoFiscal);
    ($this->emitir)(EcfType::Consumo);                 // aceptado (resumen)
    ($this->emitir)(EcfType::CreditoFiscal);           // recibido (pendiente)
    config(['ecf.fake.send' => 'rejected']);
    ($this->emitir)(EcfType::CreditoFiscal);           // rechazado

    $c = app(\App\Modules\ElectronicInvoicing\Application\EmissionStats::class)->forDays($this->company->id, Environment::Pruebas);

    expect($c['dias'])->toHaveCount(30)
        ->and(array_sum($c['aceptados']))->toBe(1)
        ->and(array_sum($c['pendientes']))->toBe(1)
        ->and(array_sum($c['rechazados']))->toBe(1)
        ->and($c['totales']['documentos'])->toBe(3)
        ->and($c['totales']['aceptacion'])->toBe(50)
        // 236 + 236: el rechazado no suma.
        ->and($c['totales']['total'])->toBe('472.00')
        ->and(collect($c['tipos'])->pluck('tipo')->all())->toBe(['31 · Factura de Crédito Fiscal Electrónica', '32 · Factura de Consumo Electrónica']);

    $this->company->forceFill(['modules' => null])->save();
    $duena = withRole(\App\Models\User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@cifras.test', 'password' => 'secret-password',
    ]), 'owner');
    $this->actingAs($duena)->get(route('panel.e-invoicing'))->assertOk()
        ->assertSee('Emisión de los últimos 30 días')->assertSee('Pasos para emitir e-CF');
});

it('los pasos para emitir se calculan de lo que ya existe', function (): void {
    $pasos = fn () => collect(app(\App\Modules\ElectronicInvoicing\Application\SetupWizard::class)->steps($this->company))->keyBy('n');

    // Recién empezado: sin dirección, sin secuencias, sin certificado.
    expect($pasos()->where('done', true)->keys()->all())->toBe([]);

    $this->ajustes->forceFill(['address' => 'Calle 1'])->save();
    ($this->certificado)();
    ($this->secuencia)(EcfType::Consumo);
    ($this->emitir)(EcfType::Consumo);   // aceptado en pruebas

    $p = $pasos();
    expect($p[1]['done'])->toBeTrue()
        ->and($p[2]['done'])->toBeTrue()
        ->and($p[3]['done'])->toBeTrue()
        ->and($p[6]['done'])->toBeTrue()
        // Sin pasar de ambiente ni encender el modo real, 7 y 8 siguen pendientes.
        ->and($p[7]['done'])->toBeFalse()
        ->and($p[8]['done'])->toBeFalse();
});
