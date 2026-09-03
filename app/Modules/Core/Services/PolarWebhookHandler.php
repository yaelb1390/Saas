<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Mail\SubscriptionConfirmedMail;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\PolarWebhookEvent;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Traduce los avisos de Polar en cambios de suscripción.
 *
 * La firma ya se verificó antes de llegar aquí: este servicio da por cierto que el aviso viene de
 * Polar y se ocupa solo de QUÉ hacer con él.
 *
 * Dos reglas gobiernan todo lo de abajo, y las dos existen para no equivocarse con el dinero:
 *
 * 1. SOLO `order.paid` añade tiempo. Una misma compra dispara varios eventos (`order.paid` y
 *    `subscription.active`, por ejemplo). Si cada uno sumara un ciclo, un solo pago regalaría
 *    meses de servicio. Los demás eventos ajustan el estado, nunca alargan el período.
 *
 * 2. EL PERÍODO LO MANDA POLAR. Cuando el aviso trae las fechas del período, se copian tal cual en
 *    lugar de calcularlas aquí. Así, aunque un evento llegue repetido o desordenado, el resultado
 *    converge siempre a la misma fecha: escribir dos veces la misma fecha no cambia nada.
 */
final class PolarWebhookHandler
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * @param  array<string, mixed>  $body  Cuerpo completo del aviso: {type, data}.
     */
    public function handle(string $eventId, array $body): PolarWebhookEvent
    {
        $type = (string) ($body['type'] ?? '');
        $event = $this->claim($eventId, $type, $body);

        // Ya estaba registrado: es un reintento de Polar (reintenta hasta 10 veces). Se responde
        // que todo bien SIN volver a aplicarlo. Esta rama es la que impide que un reintento por un
        // simple tiempo de espera acabe regalando un período extra.
        if ($event === null) {
            return PolarWebhookEvent::query()->where('event_id', $eventId)->firstOrFail();
        }

        $data = (array) ($body['data'] ?? []);

        return match ($type) {
            'order.paid' => $this->applyPayment($event, $data),
            'subscription.created', 'subscription.active', 'subscription.uncanceled' => $this->ensureActive($event, $data),
            'subscription.canceled' => $this->scheduleCancellation($event, $data),
            'subscription.revoked' => $this->revoke($event, $data),
            default => $event->resolveAs(PolarWebhookEvent::RESULT_IGNORED, 'Evento sin efecto sobre las suscripciones.'),
        };
    }

    /**
     * Reserva el evento en la bitácora. Devuelve null si ya estaba: esa es la señal de reintento.
     *
     * Se inserta ANTES de procesar y la exclusividad se delega en el índice único, no en una
     * consulta previa. Con un `exists()` dos entregas simultáneas del mismo aviso lo pasarían las
     * dos —ninguna ve la fila de la otra hasta que se escribe— y el pago se aplicaría por
     * duplicado. Con el índice único, una de las dos choca contra la base y se rinde.
     *
     * @param  array<string, mixed>  $body
     */
    private function claim(string $eventId, string $type, array $body): ?PolarWebhookEvent
    {
        try {
            return PolarWebhookEvent::create([
                'event_id' => $eventId,
                'type' => $type,
                'result' => 'received',
                'payload' => $body,
            ]);
        } catch (QueryException $e) {
            if (PolarWebhookEvent::query()->where('event_id', $eventId)->exists()) {
                return null;
            }

            throw $e; // el fallo no era un duplicado: que Polar reintente
        }
    }

    /**
     * `order.paid`: entró dinero. Es el ÚNICO evento que puede alargar el período.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyPayment(PolarWebhookEvent $event, array $data): PolarWebhookEvent
    {
        $company = $this->resolveCompany($data);

        if ($company === null) {
            return $event->resolveAs(
                PolarWebhookEvent::RESULT_UNRESOLVED,
                'Pago recibido pero no se identificó la empresa. Actívala a mano.',
            );
        }

        $plan = $this->resolvePlan($data);

        if ($plan === null) {
            return $event->resolveAs(
                PolarWebhookEvent::RESULT_UNRESOLVED,
                'El producto de Polar no está enlazado con ningún plan.',
                $company->id,
            );
        }

        $subscription = $company->subscription;
        $periodEnd = $this->periodEnd($data);

        if ($subscription === null) {
            // Alta: arranca activa, ya con un período por delante.
            $subscription = $this->subscriptions->subscribe($company, $plan, withTrial: false);
        } else {
            if ($subscription->plan_id !== $plan->id) {
                $subscription = $this->subscriptions->changePlan($subscription, $plan);
            }

            // Renovación de la que Polar no manda fechas: se encadena un ciclo al período vigente,
            // sin perder el tiempo ya pagado. Si sí manda fechas, se copian más abajo y no se
            // encadena nada (encadenar Y copiar sería contar el pago dos veces).
            if ($periodEnd === null) {
                $subscription = $this->subscriptions->registerPayment($subscription);
            }
        }

        if ($periodEnd !== null) {
            $this->applyPolarPeriod($subscription, $data, $periodEnd);
        }

        $this->link($subscription, $data);

        $this->enviarRecibo($company, $plan, $subscription, $data);

        return $event->resolveAs(
            PolarWebhookEvent::RESULT_APPLIED,
            "Pago aplicado. Plan «{$plan->name}».",
            $company->id,
        );
    }

    /**
     * El recibo del pago, al correo de quien paga.
     *
     * Va aquí y SOLO aquí: `order.paid` es el único evento donde entró dinero de verdad. Colgarlo de
     * `subscription.active` mandaría un recibo por cada cambio de estado —una misma compra dispara
     * varios eventos—, y el cliente recibiría tres correos por un pago.
     *
     * Polar manda su propio comprobante como comerciante registrado, pero llega en inglés y con su
     * marca. Este es el de la empresa: en español, con el plan, lo que se pagó y hasta cuándo.
     *
     * @param  array<string, mixed>  $data
     */
    private function enviarRecibo(Company $company, Plan $plan, Subscription $subscription, array $data): void
    {
        $owner = $company->ownerUser();
        $to = $owner?->email ?? $company->email;

        if (blank($to)) {
            return;
        }

        /*
         * `sendNow` y no `send`, aunque el correo sea `ShouldQueue`.
         *
         * En producción esto corre en Vercel, que no tiene worker de colas —solo funciones y crons—.
         * Con `send`, el correo se encolaría y no lo recogería nadie: el cliente pagaría y no
         * recibiría nada, sin ningún error visible. `sendNow` lo manda en el acto.
         *
         * Y envuelto en `rescue`: si el SMTP está caído, el webhook NO puede fallar. El pago ya está
         * aplicado y Polar reintentaría el aviso; lo que no puede pasar es que un correo caído
         * deshaga un cobro. El fallo se reporta para que quede rastro.
         */
        rescue(fn () => Mail::to($to)->sendNow(new SubscriptionConfirmedMail(
            ownerName: (string) ($owner?->name ?? $company->name),
            companyName: (string) $company->name,
            planName: (string) $plan->name,
            planPrice: $this->importePagado($data, $plan),
            billingCycleLabel: $plan->billing_cycle->label(),
            renewsAt: $subscription->current_period_end ?? now(),
            moduleLabels: $this->etiquetasDeModulos($company->activeModules()),
            loginUrl: route('login'),
            supportWhatsapp: (string) config('platform.support_whatsapp'),
            supportEmail: (string) config('platform.support_email'),
        )), report: true);
    }

    /**
     * Lo que de verdad se cobró, no lo que cuesta el plan en la tabla.
     *
     * No siempre coinciden: un descuento, un prorrateo o un impuesto cambian el importe, y un recibo
     * que dice una cifra distinta de la que salió de la tarjeta es un problema de confianza. Polar
     * manda los importes en la unidad menor de la moneda, así que se divide entre cien.
     *
     * Si el aviso no trae importe —no todos los eventos lo llevan— se cae al precio del plan, que es
     * mejor que un recibo sin cifra.
     *
     * @param  array<string, mixed>  $data
     */
    private function importePagado(array $data, Plan $plan): string
    {
        $bruto = $data['total_amount'] ?? $data['amount'] ?? null;

        return is_numeric($bruto)
            ? number_format((int) $bruto / 100, 2, '.', '')
            : (string) $plan->price;
    }

    /**
     * Los módulos contratados, con el nombre que ve una persona y no la clave interna.
     *
     * @param  array<int, string>  $claves
     * @return array<int, string>
     */
    private function etiquetasDeModulos(array $claves): array
    {
        $todos = ModuleRegistry::all();

        return array_values(array_map(static fn (string $clave): string => $todos[$clave] ?? $clave, $claves));
    }

    /**
     * `subscription.created|active|uncanceled`: la suscripción debe quedar activa. No suma tiempo
     * por sí sola; solo copia el período que diga Polar.
     *
     * @param  array<string, mixed>  $data
     */
    private function ensureActive(PolarWebhookEvent $event, array $data): PolarWebhookEvent
    {
        $company = $this->resolveCompany($data);

        if ($company === null) {
            return $event->resolveAs(
                PolarWebhookEvent::RESULT_UNRESOLVED,
                'Suscripción activa en Polar pero no se identificó la empresa.',
            );
        }

        $plan = $this->resolvePlan($data);

        if ($plan === null) {
            return $event->resolveAs(
                PolarWebhookEvent::RESULT_UNRESOLVED,
                'El producto de Polar no está enlazado con ningún plan.',
                $company->id,
            );
        }

        $subscription = $company->subscription;

        if ($subscription === null) {
            $subscription = $this->subscriptions->subscribe($company, $plan, withTrial: false);
        } elseif ($subscription->plan_id !== $plan->id || ! $subscription->isUsable()) {
            // changePlan conserva el período si seguía vigente y arranca uno nuevo si no lo estaba.
            // Nunca deja la suscripción activa «sin fecha de fin», que sería acceso ilimitado.
            $subscription = $this->subscriptions->changePlan($subscription, $plan);
        }

        $periodEnd = $this->periodEnd($data);

        if ($periodEnd !== null) {
            $this->applyPolarPeriod($subscription, $data, $periodEnd);
        } elseif ($subscription->cancelled_at !== null) {
            $subscription->update(['cancelled_at' => null]); // volvió atrás en su baja
        }

        $this->link($subscription, $data);

        return $event->resolveAs(
            PolarWebhookEvent::RESULT_APPLIED,
            "Suscripción activa. Plan «{$plan->name}».",
            $company->id,
        );
    }

    /**
     * `subscription.canceled`: pidió darse de baja, PERO el período ya está pagado.
     *
     * No se toca el estado a propósito: cortar aquí le quitaría un servicio por el que pagó. Solo
     * se deja constancia de la baja; el corte de verdad llega con `subscription.revoked`.
     *
     * @param  array<string, mixed>  $data
     */
    private function scheduleCancellation(PolarWebhookEvent $event, array $data): PolarWebhookEvent
    {
        $subscription = $this->resolveSubscription($data);

        if ($subscription === null) {
            return $event->resolveAs(PolarWebhookEvent::RESULT_UNRESOLVED, 'Baja recibida sin suscripción que la reciba.');
        }

        $subscription->update(['cancelled_at' => Carbon::now()]);

        return $event->resolveAs(
            PolarWebhookEvent::RESULT_APPLIED,
            'Baja programada: conserva el acceso hasta el fin del período pagado.',
            $subscription->company_id,
        );
    }

    /**
     * `subscription.revoked`: la baja surte efecto. Aquí sí se corta el acceso.
     *
     * @param  array<string, mixed>  $data
     */
    private function revoke(PolarWebhookEvent $event, array $data): PolarWebhookEvent
    {
        $subscription = $this->resolveSubscription($data);

        if ($subscription === null) {
            return $event->resolveAs(PolarWebhookEvent::RESULT_UNRESOLVED, 'Revocación recibida sin suscripción que la reciba.');
        }

        $this->subscriptions->cancel($subscription);

        return $event->resolveAs(
            PolarWebhookEvent::RESULT_APPLIED,
            'Suscripción revocada: se retiró el acceso.',
            $subscription->company_id,
        );
    }

    /**
     * Copia el período tal como lo manda Polar (ver la regla 2 de la cabecera).
     *
     * @param  array<string, mixed>  $data
     */
    private function applyPolarPeriod(Subscription $subscription, array $data, Carbon $end): void
    {
        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'current_period_start' => $this->date($data, 'current_period_start')
                ?? $subscription->current_period_start
                ?? Carbon::now(),
            'current_period_end' => $end,
            'cancelled_at' => null,
            'purge_at' => null,           // ya pagó: sus datos no se auto-borran
            'renewal_reminded_at' => null, // período nuevo: el aviso de vencimiento se rehabilita
        ]);
    }

    /**
     * Guarda los identificadores de Polar para poder atender los avisos futuros (ver la migración).
     *
     * @param  array<string, mixed>  $data
     */
    private function link(Subscription $subscription, array $data): void
    {
        $updates = array_filter([
            'polar_subscription_id' => $this->subscriptionId($data),
            'polar_customer_id' => $this->customerId($data),
        ], fn ($value) => filled($value));

        if ($updates !== []) {
            $subscription->update($updates);
        }
    }

    /**
     * ¿A qué empresa corresponde el aviso? Se intenta de lo más fiable a lo menos.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCompany(array $data): ?Company
    {
        // 1. Los datos que nosotros mismos adjuntamos al crear el cobro. Es el camino fiable.
        foreach ($this->metadataSources($data) as $metadata) {
            $id = $metadata['company_id'] ?? null;

            if (filled($id) && ($company = Company::find((int) $id)) !== null) {
                return $company;
            }
        }

        // 2. Identificador externo del cliente, si se creó apuntando a la empresa.
        $external = $data['customer']['external_id'] ?? ($data['customer_external_id'] ?? null);

        if (filled($external) && ($company = Company::find((int) $external)) !== null) {
            return $company;
        }

        // 3. Una suscripción ya enlazada con este cliente o esta suscripción de Polar.
        $subscription = $this->subscriptionByPolarIds($data);

        if ($subscription !== null) {
            return $subscription->company;
        }

        // 4. Último recurso: el correo del comprador. Cubre las compras hechas desde un enlace
        // suelto de Polar, sin pasar por el botón de la aplicación (no llevan datos adjuntos).
        // Es el vínculo más débil de los cuatro: depende de que el comprador use el mismo correo
        // con el que entra al sistema, así que va el último.
        $email = $data['customer']['email'] ?? ($data['customer_email'] ?? null);

        if (blank($email)) {
            return null;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null && $user->company_id !== null) {
            return Company::find($user->company_id);
        }

        return Company::query()->where('email', $email)->first();
    }

    /**
     * La suscripción de la app afectada por el aviso.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveSubscription(array $data): ?Subscription
    {
        return $this->subscriptionByPolarIds($data)
            ?? $this->resolveCompany($data)?->subscription;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function subscriptionByPolarIds(array $data): ?Subscription
    {
        $subscriptionId = $this->subscriptionId($data);
        $customerId = $this->customerId($data);

        if (blank($subscriptionId) && blank($customerId)) {
            return null;
        }

        return Subscription::query()
            ->when(filled($subscriptionId), fn ($q) => $q->orWhere('polar_subscription_id', $subscriptionId))
            ->when(filled($customerId), fn ($q) => $q->orWhere('polar_customer_id', $customerId))
            ->first();
    }

    /**
     * El plan enlazado con el producto comprado.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolvePlan(array $data): ?Plan
    {
        $productId = $data['product_id']
            ?? ($data['product']['id'] ?? null)
            ?? ($data['subscription']['product_id'] ?? null);

        return filled($productId) ? Plan::forPolarProduct((string) $productId) : null;
    }

    /**
     * Sitios donde puede venir el identificador de la suscripción de Polar según el evento: en los
     * de suscripción es el propio objeto; en los de pedido, un campo aparte.
     *
     * @param  array<string, mixed>  $data
     */
    private function subscriptionId(array $data): ?string
    {
        $id = str_starts_with((string) ($data['object'] ?? ''), 'subscription')
            ? ($data['id'] ?? null)
            : null;

        $id ??= $data['subscription_id'] ?? ($data['subscription']['id'] ?? null);

        // En los avisos de suscripción el objeto no siempre se identifica; si trae período, lo es.
        if (blank($id) && isset($data['current_period_end'], $data['id'])) {
            $id = $data['id'];
        }

        return filled($id) ? (string) $id : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function customerId(array $data): ?string
    {
        $id = $data['customer_id'] ?? ($data['customer']['id'] ?? null);

        return filled($id) ? (string) $id : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function metadataSources(array $data): array
    {
        return array_values(array_filter([
            $data['metadata'] ?? null,
            $data['subscription']['metadata'] ?? null,
            $data['checkout']['metadata'] ?? null,
        ], is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function periodEnd(array $data): ?Carbon
    {
        return $this->date($data, 'current_period_end');
    }

    /**
     * Lee una fecha del aviso, en el objeto o en la suscripción anidada. Una fecha ilegible no
     * puede tumbar el webhook: se devuelve null y se sigue por el camino de reserva.
     *
     * @param  array<string, mixed>  $data
     */
    private function date(array $data, string $key): ?Carbon
    {
        $raw = $data[$key] ?? ($data['subscription'][$key] ?? null);

        if (blank($raw)) {
            return null;
        }

        try {
            return Carbon::parse((string) $raw);
        } catch (Throwable) {
            return null;
        }
    }
}
