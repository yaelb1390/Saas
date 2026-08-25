<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Core\Database\PostgresConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * PostgreSQL usa nuestra conexión, que escribe los booleanos como Postgres los acepta.
         *
         * Sin esto no se puede guardar NINGUNA casilla de verificación: marcar «Controla stock» o
         * «Activo» devuelve un 500 porque el valor llega como el número 1 a una columna booleana.
         * El porqué completo está en la propia clase; aquí solo queda enchufada.
         *
         * Va en register() y no en boot(): la primera conexión puede abrirse antes de que arranquen
         * los servicios, y para entonces el resolvedor tiene que estar puesto.
         */
        Connection::resolverFor('pgsql', static fn (...$argumentos): PostgresConnection => new PostgresConnection(...$argumentos));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->definePosAbilities();
    }

    /**
     * Dos capacidades acotadas que el punto de venta necesita y que no justifican dar al cajero los
     * permisos completos de inventario ni de ventas.
     *
     * El rol `staff` solo tiene `pos.operate`. Sin esto, el POS estaba roto para él: la rejilla
     * pintaba las fotos rotas (el endpoint de imagen exigía `products.view`) y el botón «Imprimir
     * recibo» devolvía 403 (exigía `sales.view`). Concederle esos dos permisos enteros habría sido
     * pasarse: le abriría también el listado de inventario y el histórico de ventas.
     *
     * La regla que se expresa aquí es la real: quien puede cobrar puede ver la foto de lo que vende
     * y el recibo de lo que cobró.
     */
    private function definePosAbilities(): void
    {
        Gate::define(
            'pos.product-image',
            static fn (User $user): bool => $user->can('products.view') || $user->can('pos.operate'),
        );

        Gate::define(
            'pos.sale-receipt',
            static fn (User $user): bool => $user->can('sales.view') || $user->can('pos.operate'),
        );
    }
}
