<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\AI\Models\AiSetting;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\PolarWebhookEvent;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Support\PolarSignature;
use App\Modules\Core\Support\SubscriptionNotice;
use App\Modules\WhatsApp\Enums\MessageStatus;
use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * El estado de la plataforma entera, para el operador.
 *
 * TODAS las consultas van SIN el ámbito de empresa, y no es un descuido sino la pieza central: un
 * super administrador SIEMPRE tiene una empresa activa —`SetCurrentCompany` lo fija a la de su
 * sesión o, si no ha elegido, a la primera por id— así que un `Sale::count()` inocente devolvería
 * solo los de esa empresa, sin dar error y sin que nadie lo note mientras haya una sola empresa de
 * prueba. Es el fallo más caro de esta pantalla y por eso está escrito aquí arriba.
 *
 * Se cachea un minuto con clave `platform:`, no `company:`: con la convención de siempre, lo de la
 * plataforma se mezclaría con lo de la empresa que el operador tenga abierta.
 */
final class PlatformHealthService
{
    private const TTL = 60;

    /**
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        return Cache::remember('platform:health', self::TTL, fn (): array => $this->calcular());
    }

    /**
     * @return array<string, mixed>
     */
    public function calcular(): array
    {
        $suscripciones = Subscription::query()->with(['company', 'plan'])->get();

        // «Por vencer» no se recalcula aquí: lo decide SubscriptionNotice, que es la fuente única
        // declarada y la que ya usan el banner y la ventana emergente del cliente. Dos criterios
        // distintos para lo mismo acabarían discrepando.
        $porVencer = $suscripciones
            ->map(fn (Subscription $s): ?array => ($aviso = SubscriptionNotice::for($s)) === null ? null : [
                'empresa' => $s->company?->name ?? '—',
                'nivel' => $aviso->level,
                'dias' => $aviso->days,
                'mensaje' => $aviso->message,
                'es_prueba' => $aviso->isTrial,
            ])
            ->filter()
            ->sortBy('dias')
            ->values()
            ->all();

        return [
            'empresas' => Company::query()->count(),
            'empresas_activas' => Company::query()->where('is_active', true)->count(),
            // `User` no lleva ámbito de empresa: el aislamiento lo hace a mano cada pantalla.
            'usuarios' => User::query()->where('is_super_admin', false)->count(),
            'bloqueadas' => $this->bloqueadas($suscripciones),
            'por_vencer' => $porVencer,
            'integraciones' => $this->integraciones(),
        ];
    }

    /**
     * Empresas que no pueden operar.
     *
     * Mismo criterio, literal, que la pantalla de suspensión: sin él, el panel diría que todo está
     * bien mientras el cliente ve la puerta cerrada.
     *
     * @param  Collection<int, Subscription>  $suscripciones
     */
    private function bloqueadas(Collection $suscripciones): int
    {
        $porEmpresa = $suscripciones->keyBy('company_id');

        return Company::query()->get(['id', 'is_active'])
            ->filter(function (Company $empresa) use ($porEmpresa): bool {
                $suscripcion = $porEmpresa->get($empresa->id);

                return ! $empresa->is_active || ($suscripcion !== null && ! $suscripcion->isUsable());
            })
            ->count();
    }

    /**
     * El estado de cada servicio externo.
     *
     * Todo sale de comprobaciones que ya existían; aquí solo se juntan. Cada una devuelve su tono
     * para que la vista no tenga que decidir qué es bueno y qué es malo.
     *
     * @return array<int, array{clave: string, nombre: string, estado: string, detalle: string}>
     */
    private function integraciones(): array
    {
        $ajustesIa = AiSetting::query()->first();
        $sinRedes = Company::query()->whereNull('social_api_key')->count();
        $fallidos = WaMessage::query()->withoutGlobalScopes()
            ->where('status', MessageStatus::Failed)->count();
        // «unresolved» significa literalmente que alguien tiene que mirarlo: es la señal más
        // accionable que hay hoy en toda la plataforma.
        $sinResolver = PolarWebhookEvent::query()
            ->where('result', PolarWebhookEvent::RESULT_UNRESOLVED)->count();

        return [
            [
                'clave' => 'polar',
                'nombre' => 'Cobros (Polar)',
                'estado' => PolarSignature::fromConfig()->isConfigured() ? 'bien' : 'apagado',
                'detalle' => $sinResolver > 0
                    ? $sinResolver.' '.($sinResolver === 1 ? 'aviso sin resolver' : 'avisos sin resolver')
                    : 'sin avisos pendientes',
            ],
            [
                'clave' => 'ia',
                'nombre' => 'Inteligencia Artificial',
                'estado' => $ajustesIa?->configurado() ? 'bien' : 'apagado',
                'detalle' => $ajustesIa?->configurado()
                    ? (string) $ajustesIa->provider
                    : 'sin clave: el asistente no redacta',
            ],
            [
                'clave' => 'whatsapp',
                'nombre' => 'WhatsApp',
                'estado' => $fallidos > 0 ? 'aviso' : 'bien',
                'detalle' => $fallidos > 0
                    ? $fallidos.' '.($fallidos === 1 ? 'mensaje no salió' : 'mensajes no salieron')
                    : 'sin mensajes fallidos',
            ],
            [
                'clave' => 'redes',
                'nombre' => 'Redes sociales',
                'estado' => $sinRedes > 0 ? 'aviso' : 'bien',
                'detalle' => $sinRedes > 0
                    ? $sinRedes.' '.($sinRedes === 1 ? 'empresa sin conectar' : 'empresas sin conectar')
                    : 'todas conectadas',
            ],
        ];
    }

    /** Los avisos de Polar que nadie ha resuelto, con su motivo. */
    public function webhooksSinResolver(int $limite = 10): Collection
    {
        return PolarWebhookEvent::query()
            ->where('result', PolarWebhookEvent::RESULT_UNRESOLVED)
            ->latest('id')
            ->limit($limite)
            ->get(['id', 'type', 'note', 'company_id', 'created_at']);
    }
}
