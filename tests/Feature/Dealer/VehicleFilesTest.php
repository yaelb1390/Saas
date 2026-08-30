<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Dealer\DTOs\CreateJobData;
use App\Modules\Dealer\DTOs\CreateVehicleData;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Models\VehicleDocument;
use App\Modules\Dealer\Models\VehiclePhoto;
use App\Modules\Dealer\Services\VehicleService;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Las fotos, los papeles y los gastos de una unidad.
 *
 * LO QUE MÁS SE VIGILA AQUÍ ES EL AISLAMIENTO DE LOS FICHEROS. Son direcciones que devuelven un
 * archivo a partir de un número en la URL: basta cambiarlo para pedir el de al lado. Si fallara, se
 * filtrarían matrículas con datos del titular y contratos con la cédula del comprador entre negocios
 * distintos, y sin dejar el menor rastro —nadie revisa los registros buscando descargas—.
 */

uses(RefreshDatabase::class);

/** Una imagen de verdad: GD la va a abrir. */
function imagenDe(int $ancho = 800, int $alto = 600): UploadedFile
{
    $im = imagecreatetruecolor($ancho, $alto);
    imagefilledrectangle($im, 0, 0, $ancho, $alto, imagecolorallocate($im, 20, 100, 200));
    $ruta = tempnam(sys_get_temp_dir(), 'veh').'.jpg';
    imagejpeg($im, $ruta, 90);
    imagedestroy($im);

    return new UploadedFile($ruta, 'carro.jpg', 'image/jpeg', null, true);
}

function unidadDePrueba(string $costo = '620000', string $precio = '860000'): Vehicle
{
    return app(VehicleService::class)->create(new CreateVehicleData(
        make: 'Toyota', model: 'Corolla', year: 2019, purchaseCost: $costo, askingPrice: $precio,
    ));
}

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    app(CurrentCompany::class)->forget();
    DbTable::olvidar();

    $this->empresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'Auto Import'));
    $this->empresa->update(['modules' => ['dealer', 'crm', 'finance']]);
    app(CurrentCompany::class)->set($this->empresa->id);

    $this->duena = withRole(User::create([
        'company_id' => $this->empresa->id, 'name' => 'Dueña',
        'email' => 'duena@archivos.test', 'password' => 'secret-password',
    ]), 'owner');

    // Un vendedor: ve el patio pero no lo administra.
    $this->vendedor = User::create([
        'company_id' => $this->empresa->id, 'name' => 'Vendedor',
        'email' => 'vendedor@archivos.test', 'password' => 'secret-password',
    ]);
    $this->vendedor->givePermissionTo('vehicles.view');
});

afterEach(fn () => DbTable::olvidar());

// ------------------------------------------------------------------ El aislamiento

it('la foto de la galería de una empresa NO se le sirve a otra', function (): void {
    /*
     * EL TEST QUE MÁS IMPORTA DE ESTE FICHERO.
     *
     * El aislamiento lo dan el enlace de ruta —que pasa por el ámbito de empresa— más la
     * comprobación de que la foto es de ESE vehículo. Las dos mitades hacen falta: sin la segunda,
     * `/vehiculos/1/fotos/999` serviría la foto 999 aunque fuese de otro carro y de otra empresa.
     */
    $carro = unidadDePrueba();
    $this->actingAs($this->duena)->post(route('panel.vehicles.photos.store', $carro), [
        'photos' => [imagenDe()],
    ])->assertSessionHasNoErrors();

    $foto = VehiclePhoto::query()->firstOrFail();

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otro Dealer'));
    $otra->update(['modules' => ['dealer']]);
    $curioso = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Curioso',
        'email' => 'curioso@otro.test', 'password' => 'secret-password',
    ]), 'owner');

    app(CurrentCompany::class)->set($otra->id);

    $this->actingAs($curioso)->get(route('panel.vehicles.photos.show', [$carro, $foto]))->assertNotFound();
});

it('una foto NO se sirve por la dirección de OTRO vehículo de la misma empresa', function (): void {
    // La otra mitad del aislamiento. Dentro de una empresa no filtra nada grave, pero es el mismo
    // descuido que entre empresas sería una fuga: la comprobación tiene que estar.
    $uno = unidadDePrueba();
    $otro = app(VehicleService::class)->create(new CreateVehicleData(make: 'Honda', model: 'Civic'));

    $this->actingAs($this->duena)->post(route('panel.vehicles.photos.store', $uno), ['photos' => [imagenDe()]]);
    $foto = VehiclePhoto::query()->firstOrFail();

    $this->actingAs($this->duena)->get(route('panel.vehicles.photos.show', [$otro, $foto]))->assertNotFound();
});

it('el documento de una empresa NO se le sirve a otra', function (): void {
    // Aquí dentro va la matrícula con los datos del titular y el contrato con la cédula del
    // comprador: es la fuga más cara del módulo.
    $carro = unidadDePrueba();

    $this->actingAs($this->duena)->post(route('panel.vehicles.documents.store', $carro), [
        'type' => 'matricula',
        'document' => UploadedFile::fake()->create('matricula.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $doc = VehicleDocument::query()->firstOrFail();

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otro Dealer'));
    $otra->update(['modules' => ['dealer']]);
    $curioso = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Curioso',
        'email' => 'curioso2@otro.test', 'password' => 'secret-password',
    ]), 'owner');

    app(CurrentCompany::class)->set($otra->id);

    $this->actingAs($curioso)->get(route('panel.vehicles.documents.show', [$carro, $doc]))->assertNotFound();
});

it('un documento NO se sirve por la dirección de OTRO vehículo', function (): void {
    /*
     * ESTE TEST NACIÓ DE UNA MUTACIÓN QUE SE ESCAPÓ.
     *
     * El test de «el documento de una empresa no se le sirve a otra» pasaba IGUAL con la comprobación
     * quitada, porque el 404 lo daba antes el enlace de ruta al no encontrar el vehículo ajeno. Es
     * decir: no probaba la segunda mitad del aislamiento.
     *
     * Esta sí: mismo dueño, mismo permiso, pero el documento pedido por la dirección de otra unidad.
     * Sin la comprobación, el servidor lo entregaría.
     */
    $uno = unidadDePrueba();
    $otro = app(VehicleService::class)->create(new CreateVehicleData(make: 'Honda', model: 'Civic'));

    $this->actingAs($this->duena)->post(route('panel.vehicles.documents.store', $uno), [
        'type' => 'contrato',
        'document' => UploadedFile::fake()->create('contrato.pdf', 30, 'application/pdf'),
    ]);

    $doc = VehicleDocument::query()->firstOrFail();

    $this->actingAs($this->duena)->get(route('panel.vehicles.documents.show', [$otro, $doc]))->assertNotFound();
});

it('un vendedor NO abre los papeles de una unidad', function (): void {
    // Enseñar el carro es una cosa; abrir su matrícula, otra. Por eso los documentos exigen
    // administrar y no solo ver.
    $carro = unidadDePrueba();

    $this->actingAs($this->duena)->post(route('panel.vehicles.documents.store', $carro), [
        'type' => 'factura',
        'document' => UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'),
    ]);

    $doc = VehicleDocument::query()->firstOrFail();

    $this->actingAs($this->vendedor)->get(route('panel.vehicles.documents', $carro))->assertForbidden();
    $this->actingAs($this->vendedor)->get(route('panel.vehicles.documents.show', [$carro, $doc]))->assertForbidden();
});

it('un vendedor SÍ ve las fotos: es lo que le enseña al cliente', function (): void {
    $carro = unidadDePrueba();
    $this->actingAs($this->duena)->post(route('panel.vehicles.photos.store', $carro), ['photos' => [imagenDe()]]);

    $this->actingAs($this->vendedor)->get(route('panel.vehicles.photos', $carro))->assertOk();
});

// ------------------------------------------------------------------ La galería

it('la primera foto que se sube queda como principal', function (): void {
    // Si no, el dealer sube tres fotos y la lista sigue enseñando el recuadro gris hasta que se
    // acuerde de marcar una.
    $carro = unidadDePrueba();
    $this->actingAs($this->duena)->post(route('panel.vehicles.photos.store', $carro), ['photos' => [imagenDe()]]);

    $foto = VehiclePhoto::query()->firstOrFail();

    expect($foto->is_primary)->toBeTrue()
        // Y la copia en `vehicles`, que es la que pinta la miniatura de la lista.
        ->and($carro->fresh()->photo_path)->toBe($foto->path);
});

it('marcar una principal desmarca la anterior y cambia la miniatura', function (): void {
    $carro = unidadDePrueba();
    $this->actingAs($this->duena)->post(route('panel.vehicles.photos.store', $carro), [
        'photos' => [imagenDe(), imagenDe(700, 500)],
    ]);

    [$primera, $segunda] = VehiclePhoto::query()->orderBy('id')->get()->all();

    $this->actingAs($this->duena)->post(route('panel.vehicles.photos.primary', [$carro, $segunda]));

    expect($primera->fresh()->is_primary)->toBeFalse()
        ->and($segunda->fresh()->is_primary)->toBeTrue()
        ->and($carro->fresh()->photo_path)->toBe($segunda->path);
});

it('borrar la principal ASCIENDE la siguiente', function (): void {
    /*
     * Dejar la unidad sin principal teniendo fotos haría que la lista enseñara el recuadro de «sin
     * foto» con la galería llena, y nadie entendería por qué.
     */
    $carro = unidadDePrueba();
    $this->actingAs($this->duena)->post(route('panel.vehicles.photos.store', $carro), [
        'photos' => [imagenDe(), imagenDe(700, 500)],
    ]);

    [$primera, $segunda] = VehiclePhoto::query()->orderBy('id')->get()->all();

    $this->actingAs($this->duena)->delete(route('panel.vehicles.photos.destroy', [$carro, $primera]));

    expect(VehiclePhoto::query()->count())->toBe(1)
        ->and($segunda->fresh()->is_primary)->toBeTrue()
        ->and($carro->fresh()->photo_path)->toBe($segunda->path);
});

it('borrar la ÚLTIMA foto deja la unidad sin miniatura', function (): void {
    $carro = unidadDePrueba();
    $this->actingAs($this->duena)->post(route('panel.vehicles.photos.store', $carro), ['photos' => [imagenDe()]]);

    $foto = VehiclePhoto::query()->firstOrFail();
    $this->actingAs($this->duena)->delete(route('panel.vehicles.photos.destroy', [$carro, $foto]));

    expect($carro->fresh()->photo_path)->toBeNull()
        ->and($carro->fresh()->hasPhoto())->toBeFalse();
});

// ------------------------------------------------------------------ El costo real

it('el costo real suma TODOS los tipos de gasto, no solo las reparaciones', function (): void {
    /*
     * El ejemplo del dealer, entero: compra 620.000 + importación 25.000 + transporte 15.000 +
     * reparaciones 20.000 + documentación 10.000 = 690.000. Vendiendo en 860.000, la ganancia es
     * 170.000 y no 240.000.
     *
     * Antes solo se sumaban las reparaciones, así que el margen salía inflado en todo lo que costara
     * traer el carro al país.
     */
    $carro = unidadDePrueba(costo: '620000', precio: '860000');
    $servicio = app(VehicleService::class);

    foreach ([['importacion', '25000'], ['transporte', '15000'], ['reparacion', '20000'], ['documentacion', '10000']] as [$tipo, $monto]) {
        $servicio->addJob(new CreateJobData(
            vehicleId: $carro->id, description: 'Gasto de '.$tipo, cost: $monto, type: $tipo, done: true,
        ));
    }

    $carro->refresh();

    expect($carro->gastos())->toBe('70000.00')
        ->and($carro->costoReal())->toBe('690000.00')
        ->and($carro->margen())->toBe('170000.00');
});

it('cada gasto registra su egreso en Finanzas', function (): void {
    // Lo que se gasta en una unidad sale de la caja igual que cualquier otro gasto. Va por evento,
    // como los préstamos, para no atar el patio a Finanzas.
    /*
     * NO se crea una cuenta: la empresa ya nace con la suya.
     *
     * Al crear una empresa, `ProvisionCompanyFinance` le aprovisiona una cuenta por omisión. Mi
     * primera versión de este test creaba OTRA y comprobaba su saldo, que lógicamente se quedaba en
     * cero: el egreso iba a la primera. El test fallaba con el código bien.
     */
    $cuenta = Account::query()->where('is_default', true)->firstOrFail();

    $carro = unidadDePrueba();

    app(VehicleService::class)->addJob(new CreateJobData(
        vehicleId: $carro->id, description: 'Aranceles', cost: '25000', type: 'importacion', done: true,
    ));

    $movimiento = FinancialMovement::query()->latest('id')->first();

    expect($movimiento)->not->toBeNull()
        ->and((float) $movimiento->amount)->toBe(-25000.0)
        ->and($movimiento->description)->toContain('Importación')
        ->and((float) $cuenta->fresh()->balance)->toBe(-25000.0);
});

it('si Finanzas falla, el gasto se guarda IGUAL', function (): void {
    /*
     * Un fallo contable no puede tumbar la operación que lo originó. El costo real de la unidad es lo
     * que no se puede perder; que el egreso no llegue a la caja es un inconveniente.
     *
     * Se simula quitándole a la empresa su cuenta por omisión, que es exactamente el estado en que se
     * queda un dealer que la archiva sin marcar otra.
     */
    Account::query()->update(['is_default' => false]);

    $carro = unidadDePrueba();

    app(VehicleService::class)->addJob(new CreateJobData(
        vehicleId: $carro->id, description: 'Transporte', cost: '15000', type: 'transporte',
    ));

    expect($carro->fresh()->gastos())->toBe('15000.00')
        ->and(FinancialMovement::query()->count())->toBe(0);
});

// ------------------------------------------------------------------ Editar

it('editar corrige los datos y deja rastro en la auditoría', function (): void {
    // Faltaba por completo: un chasis mal tecleado o un precio equivocado no se podían arreglar.
    $carro = unidadDePrueba(precio: '860000');

    $this->actingAs($this->duena)->put(route('panel.vehicles.update', $carro), [
        'make' => 'Toyota', 'model' => 'Corolla', 'asking_price' => '899000', 'min_price' => '820000',
    ])->assertSessionHasNoErrors();

    $carro->refresh();

    expect((float) $carro->asking_price)->toBe(899000.0)
        ->and((float) $carro->min_price)->toBe(820000.0)
        // El código NO cambia: es el identificador con el que el dealer se refiere a la unidad.
        ->and($carro->code)->toBe('VH-000001');
});

it('editar NO deja duplicar el chasis de otra unidad', function (): void {
    $uno = unidadDePrueba();
    $uno->forceFill(['vin' => '1HGCM82633A004352'])->save();

    $otro = app(VehicleService::class)->create(new CreateVehicleData(make: 'Honda', model: 'Civic'));

    $this->actingAs($this->duena)->put(route('panel.vehicles.update', $otro), [
        'make' => 'Honda', 'model' => 'Civic', 'vin' => '1hgcm82633a004352',
    ])->assertSessionHasErrors('vin');
});

it('guardar una unidad sin tocar su chasis NO se queja de sí misma', function (): void {
    // Con la regla de unicidad mal puesta, esto daría «ya tienes un vehículo con ese chasis»: el
    // suyo. Es el fallo clásico de reutilizar el Form Request del alta para editar.
    $carro = unidadDePrueba();
    $carro->forceFill(['vin' => '1HGCM82633A004352'])->save();

    $this->actingAs($this->duena)->put(route('panel.vehicles.update', $carro), [
        'make' => 'Toyota', 'model' => 'Corolla', 'vin' => '1HGCM82633A004352',
    ])->assertSessionHasNoErrors();
});

// ------------------------------------------------------------------ Exportar

it('exportar a Excel devuelve un .xlsx con el inventario FILTRADO', function (): void {
    /*
     * Filtrado, no lo que se ve en pantalla. Exportar desde el navegador daría solo las quince filas
     * de la página actual, y quien exporta quiere el inventario.
     */
    unidadDePrueba();
    app(VehicleService::class)->create(new CreateVehicleData(make: 'Honda', model: 'Civic'));

    $r = $this->actingAs($this->duena)->get(route('panel.vehicles.export', ['formato' => 'xlsx', 'marca' => 'Honda']));

    $r->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // Un .xlsx es un zip: si lo que baja no lo es, no lo abriría Excel.
    expect(substr($r->streamedContent(), 0, 2))->toBe('PK');
});

it('el Excel de un vendedor NO lleva columnas de costo', function (): void {
    // Un fichero que se descarga y circula por correo es peor sitio todavía para filtrar el margen
    // que una pantalla.
    unidadDePrueba();

    $csv = $this->actingAs($this->vendedor)
        ->get(route('panel.vehicles.export'))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Precio')
        ->and($csv)->not->toContain('Costo real')
        ->and($csv)->not->toContain('Margen');
});

// ------------------------------------------------------------------ Agrupar

it('agrupa el patio por marca con sus totales', function (): void {
    // Agrupar filas es de la edición de pago de AG Grid. Hecho en el servidor abarca el inventario
    // entero y no solo lo que se descargó.
    unidadDePrueba(precio: '800000');
    app(VehicleService::class)->create(new CreateVehicleData(make: 'Toyota', model: 'RAV4', askingPrice: '1200000'));
    app(VehicleService::class)->create(new CreateVehicleData(make: 'Honda', model: 'Civic', askingPrice: '900000'));

    $grupos = $this->actingAs($this->duena)
        ->getJson(route('panel.vehicles.group', ['por' => 'marca']))
        ->assertOk()
        ->json('grupos');

    expect($grupos[0]['clave'])->toBe('Toyota')
        ->and($grupos[0]['unidades'])->toBe(2)
        // Con `(float)`: SQLite devuelve la suma como entero y PostgreSQL como cadena.
        ->and((float) $grupos[0]['precio'])->toBe(2000000.0);
});

it('el agrupado de un vendedor no lleva importes', function (): void {
    unidadDePrueba(precio: '800000');

    $grupos = $this->actingAs($this->vendedor)
        ->getJson(route('panel.vehicles.group', ['por' => 'marca']))
        ->assertOk()
        ->json('grupos');

    expect($grupos[0]['unidades'])->toBe(1)
        ->and($grupos[0]['precio'])->toBeNull();
});
