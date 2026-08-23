{{--
    La tira de estado: la respuesta antes que ningún dato.

    Nació en Monitoreo y sale aquí porque toda pantalla de resumen tiene la misma pregunta arriba
    —¿hay algo que mirar o puedo seguir?— y la misma respuesta de una línea. Copiar el marcado en
    cada una era la forma segura de que dentro de un mes cada una latiera distinto.

    EL PUNTO SOLO LATE CUANDO HAY ALGO QUE MIRAR. Uno latiendo siempre deja de significar nada a los
    cinco minutos, y entonces tampoco se ve el que importa. Por eso el tono no es decorativo: manda
    sobre si hay animación.

    Uso:

        <x-panel.estado tono="aviso"
                        titulo="5 cosas piden atención"
                        nota="2 empresas activas · 7 usuarios" />
--}}
@props([
    // «ok» va en verde y quieto; «aviso» en ámbar y latiendo; «grave» en rojo y latiendo.
    'tono' => 'ok',
    'titulo',
    'nota' => null,
])

@php
    $tonos = ['ok' => '#059669', 'aviso' => '#d97706', 'grave' => '#e11d48'];
    $color = $tonos[$tono] ?? $tonos['ok'];
    $late = $tono !== 'ok';
@endphp

<div {{ $attributes->merge(['class' => 'bmos-estado']) }} style="--tono: {{ $color }}">
    <span class="bmos-pulso {{ $late ? 'late' : '' }}"></span>
    <div class="min-w-0 flex-1">
        <p class="bmos-estado-titulo">{{ $titulo }}</p>
        @if (filled($nota))
            <p class="bmos-estado-nota">{{ $nota }}</p>
        @endif
    </div>

    {{-- Lo que se cuelgue a la derecha: un botón, un enlace. Va al final para que el titular mande. --}}
    {{ $slot }}
</div>
