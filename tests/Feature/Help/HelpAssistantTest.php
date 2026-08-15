<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\RoleProvisioner;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Help\Services\HelpAnswerer;
use App\Modules\Help\Services\HelpLibrary;
use App\Modules\Help\Services\HelpSearch;
use App\Modules\Help\Support\HelpArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * El asistente de ayuda.
 *
 * La máquina es la parte fácil; lo que decide si esto sirve o no es si una pregunta escrita con las
 * palabras del CLIENTE encuentra el artículo correcto. Por eso el test central no comprueba que la
 * búsqueda «funcione», sino que preguntas reales —«¿cómo devuelvo algo?»— llegan a donde tienen que
 * llegar. Si falla, casi siempre la respuesta es añadir el sinónimo a `keywords` del artículo.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ayuda Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@ayuda.test', 'password' => 'secret-password',
    ]), 'owner');
});

// ------------------------------------------------------------------ Las preguntas encuentran

/**
 * Preguntas escritas como las escribe la gente, no como está redactado el manual.
 *
 * @return array<string, array{0: string, 1: string}>
 */
dataset('preguntas reales', [
    'anular una venta' => ['cómo anulo una venta', 'anular-una-venta'],
    'devolver un producto' => ['un cliente quiere devolver lo que compró', 'anular-una-venta'],
    'me equivoqué cobrando' => ['me equivoqué al cobrar, cómo lo deshago', 'anular-una-venta'],
    'factura de la luz' => ['dónde apunto la factura de la luz', 'gastos'],
    'pagar el alquiler' => ['tengo que registrar el pago del alquiler', 'gastos'],
    'cerrar la caja' => ['cómo cierro la caja al final del día', 'turnos-de-caja'],
    'cuadrar el arqueo' => ['el arqueo no me cuadra, me falta dinero', 'turnos-de-caja'],
    'aprobar solicitud' => ['qué pasa si apruebo una solicitud', 'solicitudes-de-prestamo'],
    'el garante' => ['dónde pongo los datos del garante', 'solicitudes-de-prestamo'],
    'cobrar una cuota' => ['cómo registro el abono de una cuota', 'prestamos'],
    'meter mercancía' => ['acaba de llegar mercancía nueva del proveedor', 'entrada-de-mercancia'],
    'dar acceso a alguien' => ['quiero darle acceso a mi empleado', 'usuarios'],
    'no veo una pantalla' => ['no me aparece el módulo de préstamos', 'planes-y-modulos'],
    'sabores del helado' => ['cómo configuro los sabores del helado', 'tamanos-y-sabores'],
    'ncf' => ['me estoy quedando sin comprobantes fiscales', 'facturacion'],
    'el 606' => ['cómo saco el reporte 606', 'compras-606'],
]);

it('una pregunta real encuentra su artículo', function (string $pregunta, string $esperado): void {
    $resultado = app(HelpSearch::class)->search($pregunta)->first();

    expect($resultado)->not->toBeNull("«{$pregunta}» no encontró nada.");
    expect($resultado['article']->slug)->toBe(
        $esperado,
        "«{$pregunta}» debía llevar a «{$esperado}» y llevó a «{$resultado['article']->slug}». ".
        'Suele arreglarse añadiendo el sinónimo a las keywords del artículo.',
    );
})->with('preguntas reales');

it('lo que no está en el manual no se contesta', function (): void {
    // Es la regla que más importa: inventarse cómo funciona el sistema hace perder más tiempo que no
    // tener asistente, porque el cliente se fía de lo que lee.
    $resultado = app(HelpAnswerer::class)->answer('cuál es la capital de Australia');

    expect($resultado['article'])->toBeNull()
        ->and($resultado['answer'])->toBeNull();
});

it('sin clave de API contesta con el artículo, no con la frase del proveedor local', function (): void {
    // El proveedor local devuelve una plantilla con el contexto pegado detrás. Enseñar eso parecería
    // que el sistema está roto; la pantalla tiene que caer al artículo.
    $resultado = app(HelpAnswerer::class)->answer('cómo anulo una venta');

    expect($resultado['redactada'])->toBeFalse()
        ->and($resultado['answer'])->toBeNull()
        ->and($resultado['article']->slug)->toBe('anular-una-venta')
        ->and($resultado['article']->body)->toContain('vuelven al inventario');
});

// ------------------------------------------------------------- No se enseña lo que no se tiene

it('no se explican los módulos que la empresa no contrató', function (): void {
    $this->company->update(['modules' => ['pos', 'sales']]);
    $this->owner->refresh();

    $slugs = app(HelpSearch::class)->index($this->owner)->flatten()
        ->map(static fn (HelpArticle $a): string => $a->slug);

    expect($slugs)->not->toContain('prestamos')
        ->and($slugs)->not->toContain('gastos')
        ->and($slugs)->toContain('anular-una-venta')   // sales sí lo tiene
        ->and($slugs)->toContain('que-se-puede-deshacer'); // sin módulo: para todos
});

it('un cajero no recibe lo que no puede hacer', function (): void {
    // Explicarle a un cajero cómo anular ventas —que no puede— es peor que no contestar: le hace
    // pedirle al dueño algo creyendo que lo está haciendo mal.
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@ayuda.test', 'password' => 'secret-password',
    ]), 'staff');

    $slugs = app(HelpSearch::class)->index($cajero)->flatten()
        ->map(static fn (HelpArticle $a): string => $a->slug);

    expect($slugs)->not->toContain('anular-una-venta')
        ->and($slugs)->not->toContain('usuarios')
        ->and($slugs)->toContain('turnos-de-caja')      // sí puede abrir y cerrar caja
        ->and($slugs)->toContain('punto-de-venta');
});

it('el buscador tampoco devuelve lo que el usuario no puede ver', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero Dos',
        'email' => 'cajero2@ayuda.test', 'password' => 'secret-password',
    ]), 'staff');

    $resultados = app(HelpSearch::class)->search('cómo anulo una venta', $cajero);

    expect($resultados->pluck('article.slug'))->not->toContain('anular-una-venta');
});

// ----------------------------------------------------------------------- El manual está sano

it('todo módulo vendible tiene al menos un artículo', function (): void {
    // El guardián que impide vender una función sin documentarla: módulo nuevo, test rojo.
    $library = app(HelpLibrary::class);

    $sinManual = collect(ModuleRegistry::all())
        ->keys()
        ->filter(static fn (string $clave): bool => $library->forModule($clave)->isEmpty())
        ->values()
        ->all();

    expect($sinManual)->toBe([], 'Módulos sin ningún artículo de ayuda: '.implode(', ', $sinManual));
});

it('las cabeceras de los artículos son válidas', function (): void {
    // Un `permission:` mal escrito esconde el artículo a TODO el mundo y en silencio: nadie recibe un
    // error, simplemente ese tema deja de existir para todos.
    $modulos = array_keys(ModuleRegistry::all());
    $permisos = RoleProvisioner::PERMISSIONS;
    $problemas = [];

    foreach (app(HelpLibrary::class)->all() as $articulo) {
        if (trim($articulo->title) === '') {
            $problemas[] = "{$articulo->slug}: sin título";
        }

        if ($articulo->body === '') {
            $problemas[] = "{$articulo->slug}: sin contenido";
        }

        if ($articulo->module !== null && ! \in_array($articulo->module, $modulos, true)) {
            $problemas[] = "{$articulo->slug}: módulo «{$articulo->module}» no existe";
        }

        if ($articulo->permission !== null && ! \in_array($articulo->permission, $permisos, true)) {
            $problemas[] = "{$articulo->slug}: permiso «{$articulo->permission}» no existe";
        }

        if ($articulo->keywords === []) {
            $problemas[] = "{$articulo->slug}: sin keywords (no lo encontrará nadie que no acierte con el título)";
        }
    }

    expect($problemas)->toBe([], implode("\n", $problemas));
});

it('las rutas y los artículos relacionados existen', function (): void {
    // Un `route:` inventado revienta la pantalla al pintar el botón «Ir a la pantalla».
    $library = app(HelpLibrary::class);
    $problemas = [];

    foreach ($library->all() as $articulo) {
        if ($articulo->route !== null && ! app('router')->has($articulo->route)) {
            $problemas[] = "{$articulo->slug}: la ruta «{$articulo->route}» no existe";
        }

        foreach ($articulo->related as $slug) {
            if ($library->find($slug) === null) {
                $problemas[] = "{$articulo->slug}: relacionado «{$slug}» no existe";
            }
        }
    }

    expect($problemas)->toBe([], implode("\n", $problemas));
});

// ------------------------------------------------------------------------------------ Por HTTP

it('la ayuda está en todos los planes, sin permisos especiales', function (): void {
    // Quien más la necesita es el cliente del plan más barato: cualquier puerta lo dejaría fuera.
    $this->withoutVite();
    $this->company->update(['modules' => ['pos']]);

    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero Tres',
        'email' => 'cajero3@ayuda.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.help'))->assertOk();
});

it('preguntar por la pantalla devuelve el artículo', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->get(route('panel.help', ['q' => 'cómo anulo una venta']))
        ->assertOk()
        ->assertSee('Anular una venta')
        ->assertSee('vuelven al inventario');
});

it('una pregunta sin respuesta lo dice y no inventa', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->get(route('panel.help', ['q' => 'zzzz qwerty asdfgh']))
        ->assertOk()
        ->assertSee('No encuentro nada sobre eso');
});

it('no se puede leer un artículo tecleando su dirección si no te toca', function (): void {
    // Sin esto, bastaría con adivinar el slug para leer cómo funciona un módulo no contratado.
    $this->withoutVite();
    $this->company->update(['modules' => ['pos']]);

    $this->actingAs($this->owner)->get(route('panel.help.article', 'prestamos'))->assertNotFound();
    $this->actingAs($this->owner)->get(route('panel.help.article', 'turnos-de-caja'))->assertOk();
});

it('el botón de ayuda sale en todas las pantallas del panel', function (): void {
    $this->withoutVite();

    $html = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain(route('panel.help'));
});
