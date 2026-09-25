<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Http\Controllers;

use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Modules\SocialCommerce\Models\ContactIdentity;
use App\Modules\SocialCommerce\Models\Conversation;
use App\Modules\SocialCommerce\Models\Message;
use App\Modules\SocialCommerce\Models\OpportunityLink;
use App\Modules\SocialCommerce\Models\Rule;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * Números de Social Commerce (prompt maestro, sección 29), con lo que de verdad se puede medir.
 *
 * A propósito NO enseña «Instagram → WhatsApp»: el botón de las plantillas es un enlace `wa.me`
 * directo, no pasa por nuestro servidor, así que un clic ahí no deja ningún rastro que se pueda
 * contar. Inventar una cifra ahí sería peor que no tenerla.
 */
final class DashboardController extends Controller
{
    private const DIAS = 14;

    public function index(): View
    {
        $reglas = Rule::query()->count();
        $reglasActivas = Rule::query()->where('status', RuleStatus::Active)->count();

        $conversaciones = Conversation::query()->count();
        $mensajesEntrantes = Message::query()->where('direction', 'incoming')->count();

        $identidades = ContactIdentity::query()->count();
        $clientesEnlazados = ContactIdentity::query()->whereNotNull('customer_id')->count();

        $oportunidades = OpportunityLink::with('opportunity')->get();
        $valorOportunidades = $oportunidades->sum(fn (OpportunityLink $link): float => (float) $link->opportunity->amount);

        return view('panel.social-commerce.dashboard', [
            'reglas' => $reglas,
            'reglasActivas' => $reglasActivas,
            'conversaciones' => $conversaciones,
            'mensajesEntrantes' => $mensajesEntrantes,
            'identidades' => $identidades,
            'clientesEnlazados' => $clientesEnlazados,
            'oportunidades' => $oportunidades->count(),
            'valorOportunidades' => $valorOportunidades,
            'porDia' => $this->interaccionesPorDia(),
            'porProducto' => $this->productosMasConsultados(),
        ]);
    }

    /**
     * Mensajes entrantes por día, EN LA ZONA DEL NEGOCIO (mismo motivo que
     * `SocialAutomationController::agruparPorDia()`: agrupar en UTC partiría una noche dominicana
     * en dos). Rellena los días sin nada con cero para no inventar una densidad que no hubo.
     *
     * @return array<string, int>
     */
    private function interaccionesPorDia(): array
    {
        $zona = config('app.business_timezone');
        $desde = Carbon::now($zona)->subDays(self::DIAS - 1)->startOfDay();

        $cuentas = Message::query()
            ->where('direction', 'incoming')
            ->where('sent_at', '>=', $desde->clone()->timezone('UTC'))
            ->get()
            ->groupBy(fn (Message $m): string => $m->sent_at->timezone($zona)->toDateString())
            ->map->count();

        $serie = [];

        for ($d = $desde->clone(); $d->lte(Carbon::now($zona)); $d->addDay()) {
            $serie[$d->toDateString()] = $cuentas[$d->toDateString()] ?? 0;
        }

        return $serie;
    }

    /**
     * Los 5 productos por los que más han preguntado, contando conversaciones (no mensajes: una
     * persona que pregunta tres veces cuenta una vez, no tres).
     *
     * @return array<string, int>
     */
    private function productosMasConsultados(): array
    {
        return Conversation::with('rule.product')
            ->whereNotNull('rule_id')
            ->get()
            ->groupBy(fn (Conversation $c): string => $c->rule?->product?->name ?? 'Producto borrado')
            ->map->count()
            ->sortDesc()
            ->take(5)
            ->all();
    }
}
