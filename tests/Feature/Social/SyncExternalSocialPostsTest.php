<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
 * `redes:sincronizar-publicaciones`: el cron diario que trae, para CADA empresa con Zernio
 * conectado, lo publicado fuera del panel (desde el celular). Sin scope de empresa a propósito —es
 * lo que hace la tarea—, así que lo que hay que probar es que reparte bien entre varias, que respeta
 * el módulo apagado y que una empresa que falla no se lleva por delante a las demás.
 */

uses(RefreshDatabase::class);

const CLAVE_UNA = 'sk_'.'1111111111111111111111111111111111111111111111111111111111111111';
const CLAVE_DOS = 'sk_'.'2222222222222222222222222222222222222222222222222222222222222222';

function empresaConRedes(string $nombre, string $clave, array $modules = ['social']): \App\Modules\Core\Models\Company
{
    app(CurrentCompany::class)->forget();
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: $nombre));
    $company->update(['modules' => $modules, 'social_api_key' => $clave]);

    return $company;
}

it('sincroniza la cuenta elegible de cada empresa con la clave puesta', function (): void {
    empresaConRedes('Batidera', CLAVE_UNA);
    empresaConRedes('Repostería', CLAVE_DOS);

    // Cada empresa se distingue por SU clave (el Bearer de Zernio), no por nada que viaje aparte: es
    // justo lo que hace real el aislamiento de este cliente.
    Http::fake([
        '*/v1/accounts*' => function ($request) {
            $token = $request->header('Authorization')[0] ?? '';

            return str_contains($token, CLAVE_UNA)
                ? Http::response(['accounts' => [
                    ['_id' => 'ig_1', 'platform' => 'instagram', 'displayName' => 'La Batidera', 'needsReconnection' => false],
                ]])
                : Http::response(['accounts' => [
                    ['_id' => 'ig_2', 'platform' => 'instagram', 'displayName' => 'La Repostería', 'needsReconnection' => false],
                ]]);
        },
        '*/v1/posts/sync-external' => Http::response(['synced' => ['postsFound' => 2]]),
    ]);

    Artisan::call('redes:sincronizar-publicaciones');
    $salida = Artisan::output();

    expect($salida)->toContain('Empresas sincronizadas: 2')
        ->and($salida)->toContain('Publicaciones nuevas encontradas: 4');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sync-external') && $request->data()['accountId'] === 'ig_1');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sync-external') && $request->data()['accountId'] === 'ig_2');
});

it('una empresa con el módulo de redes apagado no se toca aunque tenga la clave', function (): void {
    empresaConRedes('Batidera', CLAVE_UNA, modules: ['pos']);

    Http::fake([
        '*/v1/accounts*' => Http::response(['accounts' => [
            ['_id' => 'ig_1', 'platform' => 'instagram', 'displayName' => 'La Batidera', 'needsReconnection' => false],
        ]]),
        '*/v1/posts/sync-external' => Http::response(['synced' => ['postsFound' => 9]]),
    ]);

    Artisan::call('redes:sincronizar-publicaciones');

    expect(Artisan::output())->toContain('Empresas sincronizadas: 0');
    Http::assertNothingSent();
});

it('una cuenta que no admite sincronizar externo, o que necesita reconectarse, se salta', function (): void {
    empresaConRedes('Batidera', CLAVE_UNA);

    Http::fake([
        '*/v1/accounts*' => Http::response(['accounts' => [
            ['_id' => 'tk_1', 'platform' => 'tiktok', 'displayName' => 'TikTok', 'needsReconnection' => false],
            ['_id' => 'ig_1', 'platform' => 'instagram', 'displayName' => 'Caducada', 'needsReconnection' => true],
        ]]),
        '*/v1/posts/sync-external' => Http::response(['synced' => ['postsFound' => 9]]),
    ]);

    Artisan::call('redes:sincronizar-publicaciones');

    expect(Artisan::output())->toContain('Publicaciones nuevas encontradas: 0');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'sync-external'));
});

it('el fallo de una empresa no detiene la sincronización de las demás', function (): void {
    empresaConRedes('Batidera', CLAVE_UNA);
    empresaConRedes('Repostería', CLAVE_DOS);

    Http::fake([
        '*/v1/accounts*' => function ($request) {
            $token = $request->header('Authorization')[0] ?? '';

            // La clave UNA representa la que ya caducó del lado de Zernio: la empresa DOS tiene que
            // sincronizarse igual, sin que el fallo de la primera tumbe la corrida entera.
            return str_contains($token, CLAVE_UNA)
                ? Http::response(['detail' => 'no autorizado'], 401)
                : Http::response(['accounts' => [
                    ['_id' => 'ig_2', 'platform' => 'instagram', 'displayName' => 'La Repostería', 'needsReconnection' => false],
                ]]);
        },
        '*/v1/posts/sync-external' => Http::response(['synced' => ['postsFound' => 1]]),
    ]);

    Artisan::call('redes:sincronizar-publicaciones');
    $salida = Artisan::output();

    expect($salida)->toContain('Empresas sincronizadas: 1')
        ->and($salida)->toContain('Publicaciones nuevas encontradas: 1');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sync-external') && $request->data()['accountId'] === 'ig_2');
});
