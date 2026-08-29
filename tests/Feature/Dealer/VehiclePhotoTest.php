<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Dealer\DTOs\CreateDealData;
use App\Modules\Dealer\DTOs\CreateVehicleData;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Services\VehicleDealService;
use App\Modules\Dealer\Services\VehicleService;
use App\Modules\Dealer\Support\VehicleImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
 * La foto de la unidad y la columna «Cliente».
 *
 * Lo que más se vigila aquí es EL AISLAMIENTO DE LA FOTO: es una dirección que devuelve un fichero a
 * partir de un id. Si fallara, se filtrarían imágenes entre negocios sin dejar el menor rastro —nadie
 * revisa los registros buscando descargas de imágenes—.
 */

uses(RefreshDatabase::class);

/** Un JPEG de verdad, del tamaño que se pida. Hace falta uno real: GD lo va a abrir. */
function fotoDe(int $ancho, int $alto): UploadedFile
{
    $im = imagecreatetruecolor($ancho, $alto);
    imagefilledrectangle($im, 0, 0, $ancho, $alto, imagecolorallocate($im, 30, 90, 200));

    $ruta = tempnam(sys_get_temp_dir(), 'veh').'.jpg';
    imagejpeg($im, $ruta, 90);
    imagedestroy($im);

    return new UploadedFile($ruta, 'carro.jpg', 'image/jpeg', null, true);
}

/** Las medidas de la foto tal como quedó guardada. */
function medidasDeLaFoto(Vehicle $v): array
{
    $bytes = VehicleImageStore::disk()->get((string) $v->photo_path);
    $im = imagecreatefromstring($bytes);

    return [imagesx($im), imagesy($im)];
}

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    app(CurrentCompany::class)->forget();
    DbTable::olvidar();

    $this->empresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'Auto Import'));
    $this->empresa->update(['modules' => ['dealer', 'crm']]);
    app(CurrentCompany::class)->set($this->empresa->id);

    $this->duena = withRole(User::create([
        'company_id' => $this->empresa->id, 'name' => 'Dueña',
        'email' => 'duena@fotos.test', 'password' => 'secret-password',
    ]), 'owner');
});

afterEach(fn () => DbTable::olvidar());

// ------------------------------------------------------------------ El recuadrado

it('la foto se guarda recuadrada en 4:3 HORIZONTAL', function (): void {
    /*
     * Al revés que la de producto, que es 3:4 vertical porque una botella es más alta que ancha. Un
     * carro es lo contrario: en el lienzo vertical saldría diminuto entre dos franjas blancas.
     *
     * Se comprueban las medidas exactas, no «que sea apaisada»: así una proporción mal puesta se ve
     * en el número y no hay que interpretarla.
     */
    $this->actingAs($this->duena)->post(route('panel.vehicles.store'), [
        'make' => 'Toyota', 'model' => 'Corolla', 'photo' => fotoDe(1200, 400),
    ])->assertSessionHasNoErrors();

    $carro = Vehicle::query()->firstOrFail();

    expect($carro->hasPhoto())->toBeTrue()
        // 1200 de ancho exige 900 de alto para ser 4:3; el lado largo no pasa del tope de 1000.
        ->and(medidasDeLaFoto($carro))->toBe([1000, 750]);
});

it('una foto pequeña NO se amplía', function (): void {
    // Una miniatura estirada se ve peor que una pequeña. El lienzo se ajusta a ella.
    $this->actingAs($this->duena)->post(route('panel.vehicles.store'), [
        'make' => 'Honda', 'model' => 'Civic', 'photo' => fotoDe(200, 120),
    ])->assertSessionHasNoErrors();

    expect(medidasDeLaFoto(Vehicle::query()->firstOrFail()))->toBe([200, 150]);
});

it('registrar sin foto no rompe nada', function (): void {
    // La foto es opcional: un carro llega y hay que anotarlo antes de tener tiempo de fotografiarlo.
    $this->actingAs($this->duena)->post(route('panel.vehicles.store'), [
        'make' => 'Kia', 'model' => 'Sportage',
    ])->assertSessionHasNoErrors();

    $carro = Vehicle::query()->firstOrFail();

    expect($carro->hasPhoto())->toBeFalse()
        ->and($carro->photoUrl())->toBeNull();
});

it('rechaza un fichero que no es una imagen', function (): void {
    // Por aquí es por donde se cuela lo que no debería subirse: un ejecutable con nombre de foto.
    $this->actingAs($this->duena)->post(route('panel.vehicles.store'), [
        'make' => 'Kia', 'model' => 'Rio',
        'photo' => UploadedFile::fake()->create('trampa.jpg', 10, 'application/x-msdownload'),
    ])->assertSessionHasErrors('photo');
});

// ------------------------------------------------------------------ El aislamiento

it('la foto se sirve a quien es de la empresa', function (): void {
    $this->actingAs($this->duena)->post(route('panel.vehicles.store'), [
        'make' => 'Toyota', 'model' => 'RAV4', 'photo' => fotoDe(800, 600),
    ]);

    $carro = Vehicle::query()->firstOrFail();

    $this->actingAs($this->duena)->get(route('panel.vehicles.photo', $carro))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('la foto de una empresa NO se le sirve a otra', function (): void {
    /*
     * EL TEST QUE MÁS IMPORTA DE ESTE FICHERO.
     *
     * Es una ruta que devuelve un FICHERO a partir de un id de la URL. Basta con cambiar el número
     * para pedir el de al lado. El aislamiento lo da el enlace de ruta pasando por el ámbito de
     * empresa —no una comprobación escrita a mano, que es lo que se olvida—.
     */
    $this->actingAs($this->duena)->post(route('panel.vehicles.store'), [
        'make' => 'Toyota', 'model' => 'RAV4', 'photo' => fotoDe(800, 600),
    ]);
    $ajeno = Vehicle::query()->firstOrFail();

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otro Dealer'));
    $otra->update(['modules' => ['dealer']]);
    $curioso = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Curioso',
        'email' => 'curioso@otro.test', 'password' => 'secret-password',
    ]), 'owner');

    app(CurrentCompany::class)->set($otra->id);

    $this->actingAs($curioso)->get(route('panel.vehicles.photo', $ajeno))->assertNotFound();
});

// ------------------------------------------------------------------ La columna «Cliente»

it('la fila enseña a quién se le apartó la unidad', function (): void {
    $carro = app(VehicleService::class)->create(new CreateVehicleData(
        make: 'Toyota', model: 'Corolla', askingPrice: '500000',
    ));
    $cliente = Customer::create(['company_id' => $this->empresa->id, 'name' => 'Pedro Comprador']);

    app(VehicleDealService::class)->open(new CreateDealData(
        vehicleId: $carro->id, customerId: $cliente->id, agreedPrice: '500000',
    ));

    $fila = $this->actingAs($this->duena)->getJson(route('panel.vehicles.data'))->json('filas.0');

    expect($fila['cliente'])->toBe('Pedro Comprador');
});

it('un trato caído NO deja un cliente fantasma en la fila', function (): void {
    // Si se quedara, la lista diría que un carro disponible es de alguien que ya no lo compró. Peor
    // que no decir nada.
    $carro = app(VehicleService::class)->create(new CreateVehicleData(
        make: 'Toyota', model: 'Corolla', askingPrice: '500000',
    ));
    $cliente = Customer::create(['company_id' => $this->empresa->id, 'name' => 'Se Arrepintió']);

    $tratos = app(VehicleDealService::class);
    $trato = $tratos->open(new CreateDealData(
        vehicleId: $carro->id, customerId: $cliente->id, agreedPrice: '500000',
    ));
    $tratos->cancel($trato);

    $fila = $this->actingAs($this->duena)->getJson(route('panel.vehicles.data'))->json('filas.0');

    expect($fila['cliente'])->toBeNull();
});

it('la columna Cliente NO dispara una consulta por unidad', function (): void {
    /*
     * Con treinta unidades en pantalla, preguntar quién tiene cada una de una en una serían treinta
     * consultas en la pantalla que el dealer deja abierta todo el día. Se fija con un número: es lo
     * único que impide que alguien meta un `foreach` con una consulta dentro.
     */
    $servicio = app(VehicleService::class);
    for ($i = 0; $i < 30; $i++) {
        $servicio->create(new CreateVehicleData(make: 'Marca'.$i, model: 'Modelo'.$i));
    }

    $this->actingAs($this->duena);

    $consultas = 0;
    DB::listen(function () use (&$consultas): void {
        $consultas++;
    });

    $filas = $this->getJson(route('panel.vehicles.data'))->assertOk()->json('filas');

    expect($filas)->toHaveCount(30)
        // Las unidades, los gastos agrupados, los clientes agrupados, y lo que el marco haga para
        // resolver la sesión y los permisos. Lejos de treinta.
        ->and($consultas)->toBeLessThan(20);
});

// ------------------------------------------------------------------ Sin la columna

it('la pantalla se pinta aunque falte la columna de la foto', function (): void {
    // Las migraciones se aplican a mano: entre que sale el código y alguien migra, la pantalla no
    // puede caerse con «columna desconocida».
    Schema::table('vehicles', fn ($t) => $t->dropColumn('photo_path'));
    DbTable::olvidar();

    app(VehicleService::class)->create(new CreateVehicleData(make: 'Toyota', model: 'Corolla'));

    $this->actingAs($this->duena)->get(route('panel.vehicles'))->assertOk();

    $fila = $this->actingAs($this->duena)->getJson(route('panel.vehicles.data'))->json('filas.0');

    expect($fila['foto'])->toBeNull();
});
