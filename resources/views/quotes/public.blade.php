{{--
    La cotización tal como la ve el cliente.

    Sin menú, sin sesión y sin nada del panel: quien abre esto no es usuario del sistema, es alguien
    que pidió un precio y recibió un enlace por WhatsApp. Casi siempre lo abre en el móvil y de pie,
    así que es de una sola columna, con el total grande y el botón de descargar bien a mano.
--}}
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cotización {{ $quote->code }} · {{ $quote->company?->name }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bmos-cot-body">
    <main class="bmos-cot">
        <header class="bmos-cot-cab">
            <p class="bmos-cot-negocio">{{ $quote->company?->name ?? 'Comercio' }}</p>
            @if (filled($quote->company?->phone))
                <p class="bmos-cot-muted">{{ $quote->company->phone }}</p>
            @endif

            <p class="bmos-cot-titulo">Cotización {{ $quote->code }}</p>
            <p class="bmos-cot-muted">Para {{ $quote->customer_name }}</p>
        </header>

        {{-- Lo primero que se mira es el total. Va arriba y grande, antes del detalle. --}}
        <div class="bmos-cot-total">
            <span class="bmos-cot-muted">Total</span>
            <strong>{{ money((float) $quote->total) }}</strong>
        </div>

        @php $vencida = $quote->estaCaducada(); @endphp

        @if ($quote->valid_until)
            {{-- Si ya venció se dice CLARO y en rojo. Que el cliente se entere al llegar al mostrador
                 de que el precio ya no vale es peor que decírselo aquí. --}}
            <p class="bmos-cot-validez" @if ($vencida) data-vencida="si" @endif>
                @if ($vencida)
                    Esta cotización venció el {{ $quote->valid_until->format('d/m/Y') }}.
                    Escríbenos y te confirmamos los precios de hoy.
                @else
                    Precios válidos hasta el {{ $quote->valid_until->format('d/m/Y') }}.
                @endif
            </p>
        @endif

        <ul class="bmos-cot-lineas">
            @foreach ($quote->items as $item)
                <li>
                    <div class="min-w-0">
                        <p class="bmos-cot-concepto">{{ $item->description }}</p>
                        <p class="bmos-cot-muted">
                            {{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}
                            × {{ money((float) $item->unit_price) }}
                        </p>
                    </div>
                    <span class="bmos-cot-importe">{{ money((float) $item->subtotal) }}</span>
                </li>
            @endforeach
        </ul>

        <dl class="bmos-cot-sumas">
            <div><dt>Subtotal</dt><dd>{{ money((float) $quote->subtotal) }}</dd></div>
            <div><dt>ITBIS</dt><dd>{{ money((float) $quote->tax) }}</dd></div>
            @if (bccomp((string) $quote->discount_total, '0', 2) > 0)
                <div><dt>Descuento</dt><dd>−{{ money((float) $quote->discount_total) }}</dd></div>
            @endif
            <div class="bmos-cot-suma-total"><dt>Total</dt><dd>{{ money((float) $quote->total) }}</dd></div>
        </dl>

        @if (filled($quote->notes))
            <div class="bmos-cot-notas">{!! nl2br(e($quote->notes)) !!}</div>
        @endif

        {{-- El PDF va por su propia dirección firmada: la de esta página no sirve para el archivo, y
             el cliente muchas veces lo que quiere es guardarlo o reenviarlo a alguien. --}}
        <a href="{{ URL::temporarySignedRoute('quotes.public.pdf', now()->addDays(30), ['quote' => $quote->id]) }}"
           class="bmos-cot-boton">Descargar en PDF</a>

        <p class="bmos-cot-pie">Este documento es una cotización, no un comprobante fiscal.</p>
    </main>
</body>
</html>
