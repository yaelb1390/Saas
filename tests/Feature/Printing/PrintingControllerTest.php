<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Models\PrinterPreference;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * LOS ENDPOINTS DEL CENTRO DE IMPRESIÓN: CRUD de impresoras y plantillas, asignación por módulo,
 * historial, aislamiento entre empresas y permisos.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Controlador Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->admin = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@ctrl.test', 'password' => 'secret-password',
    ]), 'owner');
});

it('da de alta una impresora por el formulario', function (): void {
    $this->actingAs($this->admin)->post(route('panel.printing.printers.store'), [
        'name' => 'Térmica caja 1', 'connection_type' => 'bluetooth', 'paper_size' => '80mm',
    ])->assertRedirect()->assertSessionHas('panel_ok');

    expect(Printer::where('name', 'Térmica caja 1')->exists())->toBeTrue();
});

it('edita una impresora existente', function (): void {
    $printer = Printer::create(['name' => 'Vieja', 'connection_type' => 'usb', 'paper_size' => '58mm']);

    $this->actingAs($this->admin)->put(route('panel.printing.printers.update', $printer), [
        'name' => 'Nueva', 'connection_type' => 'usb', 'paper_size' => '58mm',
    ])->assertRedirect();

    expect($printer->fresh()->name)->toBe('Nueva');
});

it('borra (archiva) una impresora', function (): void {
    $printer = Printer::create(['name' => 'X', 'connection_type' => 'browser', 'paper_size' => 'a4']);

    $this->actingAs($this->admin)->delete(route('panel.printing.printers.destroy', $printer))->assertRedirect();

    expect(Printer::find($printer->id))->toBeNull();
});

it('marca el estado de una impresora', function (): void {
    $printer = Printer::create(['name' => 'X', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']);

    $this->actingAs($this->admin)->post(route('panel.printing.printers.status', $printer), ['status' => 'conectada'])
        ->assertRedirect();

    expect($printer->fresh()->last_status->value)->toBe('conectada');
});

it('marca y quita la impresora predeterminada del usuario que la pide', function (): void {
    $printer = Printer::create(['name' => 'X', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']);

    $this->actingAs($this->admin)->post(route('panel.printing.printers.default'), ['printer_id' => $printer->id])
        ->assertRedirect();

    expect(PrinterPreference::where('user_id', $this->admin->id)->first()->default_printer_id)->toBe($printer->id);

    $this->actingAs($this->admin)->post(route('panel.printing.printers.default'), ['printer_id' => null])->assertRedirect();
    expect(PrinterPreference::where('user_id', $this->admin->id)->first()->default_printer_id)->toBeNull();
});

it('guarda y edita una plantilla', function (): void {
    $this->actingAs($this->admin)->post(route('panel.printing.templates.store'), [
        'document_type' => 'sale_ticket', 'name' => 'Mi ticket', 'paper_size' => '80mm',
    ])->assertRedirect();

    $template = PrintTemplate::where('name', 'Mi ticket')->firstOrFail();

    $this->actingAs($this->admin)->put(route('panel.printing.templates.update', $template), [
        'document_type' => 'sale_ticket', 'name' => 'Renombrada', 'paper_size' => '80mm',
    ])->assertRedirect();

    expect($template->fresh()->name)->toBe('Renombrada');
});

it('borra una plantilla', function (): void {
    $template = PrintTemplate::create(['document_type' => 'sale_ticket', 'name' => 'X', 'paper_size' => '80mm', 'layout' => []]);

    $this->actingAs($this->admin)->delete(route('panel.printing.templates.destroy', $template))->assertRedirect();

    expect(PrintTemplate::find($template->id))->toBeNull();
});

it('asigna una impresora a un modulo y queda en el mapa', function (): void {
    $printer = Printer::create(['name' => 'Ventas', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']);

    $this->actingAs($this->admin)->post(route('panel.printing.modules.assign'), [
        'module' => 'sales', 'printer_id' => $printer->id,
    ])->assertRedirect();

    expect(data_get($this->company->fresh()->settings, 'printing.modules.sales'))->toBe($printer->id);
});

it('el render devuelve html y escpos para un tipo de documento, con datos de muestra', function (): void {
    $res = $this->actingAs($this->admin)->postJson(route('panel.printing.render'), [
        'document_type' => 'sale_ticket',
    ])->assertOk();

    $res->assertJsonStructure(['html', 'escpos_base64', 'ancho_mm', 'es_rollo', 'reference']);
    expect($res->json('html'))->toContain('TICKET DE VENTA');
});

it('el render resuelve la impresora del modulo cuando se pide', function (): void {
    $printer = Printer::create(['name' => 'Ventas', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']);
    $this->company->fresh()->update(['settings' => ['printing' => ['modules' => ['sales' => $printer->id]]]]);

    $res = $this->actingAs($this->admin)->postJson(route('panel.printing.render'), [
        'document_type' => 'sale_ticket', 'module' => 'sales',
    ])->assertOk();

    expect($res->json('printer.id'))->toBe($printer->id)
        ->and($res->json('printer.connection_type'))->toBe('bluetooth');
});

it('logJob registra un trabajo en el historial', function (): void {
    $this->actingAs($this->admin)->postJson(route('panel.printing.jobs.store'), [
        'document_type' => 'sale_ticket', 'status' => 'printed', 'copies' => 2,
    ])->assertCreated();

    $job = PrintJob::firstOrFail();
    expect($job->user_id)->toBe($this->admin->id)
        ->and($job->copies)->toBe(2)
        ->and($job->status->value)->toBe('printed')
        ->and($job->printed_at)->not->toBeNull();
});

/*
 * AISLAMIENTO. La impresora y la plantilla de otra empresa no se ven ni se pueden tocar.
 */
it('no se puede editar ni borrar la impresora de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena'));
    app(CurrentCompany::class)->set($otra->id);
    $ajena = Printer::create(['name' => 'Ajena', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->admin)
        ->put(route('panel.printing.printers.update', $ajena->id), ['name' => 'Robada', 'connection_type' => 'bluetooth', 'paper_size' => '80mm'])
        ->assertNotFound();

    $this->actingAs($this->admin)->delete(route('panel.printing.printers.destroy', $ajena->id))->assertNotFound();
});

/*
 * PERMISOS. `printing.view` abre la pantalla y deja marcar la propia predeterminada; `printing.manage`
 * es lo único que exige dar de alta, editar o borrar impresoras y plantillas.
 */
it('el cajero (solo printing.view) ve la pantalla y marca su predeterminada, pero no da de alta impresoras', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@ctrl.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.printing.index'))->assertOk();

    $printer = Printer::create(['name' => 'X', 'connection_type' => 'bluetooth', 'paper_size' => '80mm']);
    $this->actingAs($cajero)->post(route('panel.printing.printers.default'), ['printer_id' => $printer->id])->assertRedirect();

    $this->actingAs($cajero)->post(route('panel.printing.printers.store'), [
        'name' => 'Nueva', 'connection_type' => 'bluetooth', 'paper_size' => '80mm',
    ])->assertForbidden();

    $this->actingAs($cajero)->post(route('panel.printing.modules.assign'), ['module' => 'sales', 'printer_id' => $printer->id])
        ->assertForbidden();
});
