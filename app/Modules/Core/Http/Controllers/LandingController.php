<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Plan;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

/**
 * La presentación del producto. Pública: es la primera pantalla de quien todavía no es cliente.
 *
 * Existe porque hasta ahora no había ninguna. Quien pulsaba el enlace desde Instagram o desde un
 * mensaje de WhatsApp aterrizaba en `/` —que redirige al panel y de ahí al login— y se encontraba un
 * formulario de contraseña sin una sola línea que dijera qué es esto. Se le pedía la cuenta antes de
 * contarle el producto.
 *
 * NADA DE LO QUE ENSEÑA ESTÁ ESCRITO A MANO. Los módulos salen de `ModuleRegistry`, que es el mismo
 * sitio del que salen los que una empresa contrata, y los planes de la tabla. Un texto copiado se
 * queda viejo el día que se añade un módulo o cambia un precio, y entonces la página que vende el
 * producto miente sobre él —que es la peor página donde tener una mentira—.
 */
final class LandingController extends Controller
{
    /**
     * Los módulos que se destacan arriba, en este orden.
     *
     * No son todos: quince tarjetas seguidas no las lee nadie. Son los que resuelven el problema por
     * el que un negocio pequeño busca un sistema —cobrar, saber qué hay, saber cuánto entró— y los
     * dos que aquí no tiene casi nadie y son la razón para elegir este: WhatsApp y redes.
     *
     * @var list<string>
     */
    private const DESTACADOS = ['pos', 'inventory', 'whatsapp', 'social', 'billing', 'reports'];

    public function __invoke(): View
    {
        $modulos = ModuleRegistry::all();

        return view('landing.index', [
            // Etiqueta y descripción salen del registro: si mañana se añade un módulo, aparece aquí
            // sin que nadie tenga que acordarse de esta pantalla.
            'destacados' => collect(self::DESTACADOS)
                ->filter(static fn (string $clave): bool => isset($modulos[$clave]))
                ->map(static fn (string $clave): array => [
                    'clave' => $clave,
                    'nombre' => $modulos[$clave],
                    'detalle' => ModuleRegistry::description($clave),
                ])
                ->values()
                ->all(),

            // El resto, como lista suelta: dice que hay más sin robarle sitio a lo de arriba.
            'resto' => collect($modulos)
                ->reject(static fn (string $nombre, string $clave): bool => in_array($clave, self::DESTACADOS, true))
                ->values()
                ->all(),

            'planes' => Plan::query()->where('is_active', true)->orderBy('price')->get(),

            'diasPrueba' => (int) config('bmos.trial.days'),

            // El WhatsApp del operador, que es por donde llega la gente desde Instagram.
            'whatsapp' => preg_replace('/\D+/', '', (string) config('platform.support_whatsapp')),
        ]);
    }
}
