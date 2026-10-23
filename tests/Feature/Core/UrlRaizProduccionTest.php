<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/*
 * En producción las URL que genera la aplicación salen de APP_URL, no del host de la petición.
 *
 * El cron de Vercel entra por la dirección interna del deploy (`saas-xxxx-….vercel.app`) y los
 * correos que manda llevaban el botón y el logo apuntando ahí, tras la protección de despliegues
 * de Vercel. Cada prueba simula esa petición y vuelve a arrancar el proveedor con el entorno dado.
 */

/** Simula una petición entrando por la dirección interna del deploy y arranca el proveedor. */
function arrancarConHostDelDeploy(string $entorno, string $appUrl): void
{
    app()['env'] = $entorno;
    config(['app.url' => $appUrl]);
    URL::forceRootUrl(null);
    URL::setRequest(Request::create('https://saas-1uu2su5yy-yaelb1390s-projects.vercel.app/tareas/avisar-vencimientos'));

    (new AppServiceProvider(app()))->boot();
}

afterEach(function () {
    URL::forceRootUrl(null);
});

it('en producción, el login y el logo de los correos salen del dominio público', function () {
    arrancarConHostDelDeploy('production', 'https://bmos.bm1390.cloud');

    expect(route('login'))->toBe('https://bmos.bm1390.cloud/login')
        ->and(asset('images/bm-logo.png'))->toBe('https://bmos.bm1390.cloud/images/bm-logo.png');
});

it('fuera de producción cada copia sigue usando su propio host', function () {
    arrancarConHostDelDeploy('preview', 'https://bmos.bm1390.cloud');

    expect(route('login'))->toBe('https://saas-1uu2su5yy-yaelb1390s-projects.vercel.app/login');
});

it('no ancla a un APP_URL que no es un https público', function (string $appUrl) {
    arrancarConHostDelDeploy('production', $appUrl);

    expect(route('login'))->toBe('https://saas-1uu2su5yy-yaelb1390s-projects.vercel.app/login');
})->with(['http://localhost', 'https://localhost', '', 'http://bmos.bm1390.cloud']);
