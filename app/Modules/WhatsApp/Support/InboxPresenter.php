<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Support;

use App\Modules\WhatsApp\Enums\MessageDirection;
use App\Modules\WhatsApp\Models\WaConversation;
use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Aplana la bandeja a datos serializables. La usan tanto la vista inicial como el endpoint de
 * sondeo, de modo que ambos rinden exactamente la misma forma y no hay dos verdades.
 */
final class InboxPresenter
{
    /**
     * @return array{conversations: array<int, array<string, mixed>>, thread: array<int, array<string, mixed>>, active_phone: ?string}
     */
    public function payload(?string $phone = null): array
    {
        $conversations = WaConversation::query()
            ->with(['customer', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->latest('last_message_at')
            ->get();

        $active = $phone !== null && $phone !== ''
            ? $conversations->firstWhere('phone', $phone)
            : $conversations->first();

        $thread = $active?->messages()->oldest()->limit(200)->get();

        return [
            'conversations' => $conversations
                ->map(fn (WaConversation $c): array => $this->conversationRow($c))
                ->all(),

            'thread' => $thread === null
                ? []
                : $thread->map(fn (WaMessage $m): array => $this->messageRow($m))->all(),

            'active_phone' => $active === null ? null : (string) $active->phone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationRow(WaConversation $conversation): array
    {
        /** @var WaMessage|null $last */
        $last = $conversation->messages->first();

        /** @var Carbon|null $lastAt */
        $lastAt = $conversation->last_message_at;

        $name = $conversation->name === null ? null : (string) $conversation->name;
        $phone = (string) $conversation->phone;

        return [
            'phone' => $phone,
            'title' => $name ?? $phone,
            'initials' => $this->initials($name),
            'preview' => $last === null ? 'Sin mensajes' : (string) $last->body,
            'out' => $last !== null && $last->direction === MessageDirection::Outbound,
            'time' => $lastAt?->diffForHumans(short: true),
            'is_customer' => $conversation->customer !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messageRow(WaMessage $message): array
    {
        /** @var Carbon|null $at */
        $at = $message->sent_at ?? $message->created_at;

        return [
            'id' => $message->id,
            'out' => $message->direction === MessageDirection::Outbound,
            'body' => (string) $message->body,
            'time' => $at?->format('H:i'),
            'status' => $message->status->value,
        ];
    }

    /**
     * Iniciales solo si hay un nombre real: un número de teléfono no las produce con sentido.
     */
    private function initials(?string $name): ?string
    {
        return $name !== null && preg_match('/\p{L}/u', $name) === 1
            ? Str::upper(Str::substr($name, 0, 2))
            : null;
    }
}
