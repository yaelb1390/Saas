<?php

namespace Tests;

use App\Modules\Core\Support\DbTable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Salvaguarda: los tests solo pueden correr contra SQLite en memoria.
     *
     * Con la configuración cacheada (php artisan optimize), Laravel ignora las variables de
     * phpunit.xml y usa la conexión real. En ese escenario, RefreshDatabase ejecuta
     * migrate:fresh CONTRA LA BASE DE DATOS DE DESARROLLO Y LA BORRA.
     *
     * La comprobación va aquí, en el refresco de la aplicación, porque es el último punto que
     * se ejecuta ANTES de que setUpTraits() active RefreshDatabase. Hacerlo en setUp() sería
     * inútil: para entonces la base ya estaría destruida.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        /*
         * El memo de «¿existe esta tabla?» NO puede sobrevivir entre tests.
         *
         * `DbTable` recuerda el resultado durante todo el proceso, y en producción eso es lo
         * correcto: el catálogo solo cambia el día que alguien migra. En la suite es justo al revés
         * —un test puede borrar una tabla a propósito para comprobar que la pantalla aguanta sin
         * ella— y ese «no existe» se quedaba pegado para todos los tests siguientes, que ya corrían
         * con la tabla restaurada.
         *
         * Costó encontrarlo: los tests afectados pasaban SUELTOS y fallaban en la suite completa,
         * que es la peor forma de fallar que hay.
         */
        DbTable::olvidar();

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                'Los tests deben correr sobre SQLite en memoria, pero la conexión activa es '.
                "«{$connection}» ({$database}). Casi seguro es la configuración cacheada: ".
                'ejecuta «php artisan config:clear» antes de los tests (o usa «composer test»).'
            );
        }
    }
}
