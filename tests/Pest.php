<?php

use App\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Asigna un rol de empresa al usuario de prueba.
 *
 * Los roles de spatie están particionados por empresa (teams), así que hay que fijar el equipo
 * antes de asignarlos. Sin rol, un usuario no tiene ningún permiso y el panel le responde 403:
 * es justo lo que queremos en producción, pero los tests deben declarar con qué rol operan.
 */
function withRole(User $user, string $role = 'owner'): User
{
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($user->company_id);

    $user->assignRole($role);
    $registrar->forgetCachedPermissions();

    return $user;
}
