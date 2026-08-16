{{--
    La franja de cifras, con su icono y su color.

    Recibe `$fichas`, una lista de `[tono, icono, valor, etiqueta]`. Lo construyen los dos sitios que
    la usan —la tarjeta de una automatización y el resumen de todas— porque las métricas no son las
    mismas, pero la pinta sí tiene que serlo.

    El color codifica el PAPEL de cada cifra y su posición no cambia nunca, así que la vista aprende
    dónde mirar en dos tarjetas. Cuatro colores sueltos serían un arcoíris.

    El tono va en DOS sitios y no es redundante: `data-tono` en la ficha define las variables que
    tiñen el número y el anillo, y la clase `.tone-*` en la pastilla del icono le da su fondo. Si se
    pusiera la clase en la ficha entera, le impondría también ese fondo al resto.

    OJO: la tarjeta lleva `style="--tono: <color de la red>"` para su franja de marca, y eso hereda a
    todos sus hijos. Una ficha sin `data-tono` saldría del color de Instagram.
--}}
@php
    // Los trazos, sueltos, para que las tuplas de arriba no sean ilegibles.
    $trazos = [
        // Un bocadillo: alguien comentó.
        'comentario' => 'M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.068.157 2.148.279 3.238.364.466.037.893.281 1.153.671L12 21l2.652-3.978c.26-.39.687-.634 1.153-.67 1.09-.086 2.17-.208 3.238-.365 1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z',
        // Un avión de papel: el mensaje que sale.
        'mensaje' => 'M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5',
        // Personas.
        'personas' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
        // Visto: todo salió.
        'bien' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        // Aviso: algo falló.
        'fallo' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
        // Un rayo: las que están trabajando.
        'encendida' => 'm3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z',
    ];
@endphp

<div class="bmos-cifras">
    @foreach ($fichas as [$tono, $icono, $valor, $etiqueta])
        {{-- `es-neutra` es una clase y no un tono: apaga la ficha sin darle color. --}}
        <div class="bmos-cifra {{ $tono === 'es-neutra' ? 'es-neutra' : '' }}"
             @if ($tono !== 'es-neutra') data-tono="{{ $tono }}" @endif>
            <span class="bmos-cifra-icono {{ $tono === 'es-neutra' ? 'bg-slate-100 text-slate-400' : $tono }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $trazos[$icono] }}"/>
                </svg>
            </span>
            <p class="bmos-cifra-valor">{{ number_format($valor) }}</p>
            <p class="bmos-cifra-etq">{{ $etiqueta }}</p>
        </div>
    @endforeach
</div>
