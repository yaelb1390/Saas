<?php

declare(strict_types=1);

namespace App\Modules\POS\Services;

use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\OptionResolver;
use App\Modules\POS\Models\HeldOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Aparcar, listar, recuperar y descartar pedidos.
 *
 * Del carrito solo se guarda QUÉ se eligió: producto, cantidad y opciones. Los precios se recalculan
 * al recuperarlo, así que un pedido aparcado antes de un cambio de tarifa se cobra a la tarifa
 * vigente y no a la que había cuando se dejó a medias.
 */
final class HeldOrderService
{
    public function __construct(private readonly OptionResolver $options) {}

    /**
     * Aparca el carrito y devuelve el pedido con su referencia.
     *
     * @param  array<int, array<string, mixed>>  $cart
     */
    public function hold(array $cart, ?string $customerName = null): HeldOrder
    {
        $lineas = $this->sanear($cart);

        $session = CashSession::query()
            ->where('status', CashSessionStatus::Open)->latest('opened_at')->first();

        return HeldOrder::create([
            'cash_session_id' => $session?->id,
            'user_id' => auth()->id(),
            'reference' => $this->nextReference(),
            'customer_name' => $customerName,
            'payload' => $lineas,
            'total' => $this->totalDe($lineas),
        ]);
    }

    /**
     * Pedidos aparcados de la empresa activa, del más reciente al más antiguo.
     *
     * @return Collection<int, HeldOrder>
     */
    public function pending(): Collection
    {
        return HeldOrder::query()->latest('id')->get();
    }

    /**
     * Recupera el pedido con los PRECIOS DE HOY, listo para pintar en el ticket.
     *
     * Un producto que ya no exista o esté descatalogado desaparece de la lista en vez de romper la
     * recuperación: el cajero ve el resto del pedido y decide.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resume(HeldOrder $order): array
    {
        $lineas = [];

        foreach ($order->payload as $item) {
            $product = Product::query()->with('optionGroups')->find((int) ($item['id'] ?? 0));

            if ($product === null || ! $product->is_active) {
                continue;
            }

            $opciones = $this->options->resolve($product, (array) ($item['options'] ?? []));

            $lineas[] = [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'price' => (float) $this->options->unitPrice($product, $opciones),
                'image' => $product->imageUrl(),
                'qty' => (int) ($item['qty'] ?? 1),
                'opciones' => array_map(fn ($o): array => [
                    'id' => $o->optionId,
                    'name' => $o->optionName,
                    'price_delta' => $o->priceDelta,
                ], $opciones),
            ];
        }

        return $lineas;
    }

    public function discard(HeldOrder $order): void
    {
        $order->delete();
    }

    /**
     * Deja solo lo que decide el pedido; nada de precios ni nombres, que se releen.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<int, array{id: int, qty: int, options: array<int, int>}>
     */
    private function sanear(array $cart): array
    {
        $lineas = [];

        foreach ($cart as $item) {
            $id = (int) ($item['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $lineas[] = [
                'id' => $id,
                'qty' => max(1, (int) ($item['qty'] ?? 1)),
                'options' => array_values(array_map(
                    static fn ($o): int => (int) $o,
                    (array) ($item['options'] ?? []),
                )),
            ];
        }

        return $lineas;
    }

    /**
     * Importe orientativo para la lista de aparcados. Se calcula ahora para no tener que resolver
     * todos los pedidos cada vez que se pinta la lista.
     *
     * @param  array<int, array{id: int, qty: int, options: array<int, int>}>  $lineas
     */
    private function totalDe(array $lineas): string
    {
        $total = '0';

        $productos = Product::query()
            ->whereKey(array_column($lineas, 'id'))
            ->with('optionGroups')
            ->get()
            ->keyBy('id');

        foreach ($lineas as $linea) {
            $product = $productos->get($linea['id']);

            if ($product === null) {
                continue;
            }

            $precio = $this->options->unitPrice($product, $this->options->resolve($product, $linea['options']));
            $total = bcadd($total, bcmul($precio, (string) $linea['qty'], 2), 2);
        }

        return $total;
    }

    /**
     * Referencia corta del día: E-01, E-02...
     *
     * Se reinicia cada día porque es un identificador de mostrador, no un folio: el cajero lo canta
     * en voz alta y «E-07» se entiende mejor que «E-000743». El índice único por empresa protege del
     * choque si dos cajas aparcan a la vez.
     */
    private function nextReference(): string
    {
        $hoy = HeldOrder::query()->whereDate('created_at', Carbon::now()->toDateString())->count();

        for ($intento = 1; $intento <= 50; $intento++) {
            $candidata = 'E-'.str_pad((string) ($hoy + $intento), 2, '0', STR_PAD_LEFT);

            if (! HeldOrder::query()->where('reference', $candidata)->exists()) {
                return $candidata;
            }
        }

        return 'E-'.Carbon::now()->format('His');
    }
}
