{{--
    Botón que pide confirmación antes de enviar su formulario.

    Sustituye al viejo patrón `<form onsubmit="return confirm …">`, que sacaba la ventana gris del
    navegador: tipografía del sistema, «Aceptar/Cancelar» y el dominio como título. (Se escribe sin
    el paréntesis a propósito: hay un test que recorre las vistas buscando esa llamada, y este
    fichero no debe ser su primera víctima.) Además de desentonar, no distinguía lo grave de lo
    trivial —archivar un cliente y destruir un plan preguntaban igual—, y por eso este componente
    obliga a decidir dos cosas:

      - `tone`: «danger» (rojo) solo si se destruyen datos; «neutral» (índigo) si no se borra nada,
        como reactivar un usuario o recibir una orden de compra.
      - `note`: qué pasa después. Con `irreversible` se pinta sobre fondo rojo, y se reserva a lo
        que de verdad no tiene vuelta atrás.

    EL FORMULARIO SE PINTA EN EL SERVIDOR, oculto, con su token y su método. El JavaScript solo lo
    envía: nunca fabrica un CSRF ni un `_method`, que es la misma regla que sigue el borrado de
    empresas.

    Uso:

        <x-panel.confirm-action
            :action="route('panel.products.destroy', $product)"
            title="¿Eliminar «{{ $product->name }}»?"
            message="Se archiva y deja de aparecer en el inventario."
            class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50"
            tooltip="Eliminar">
            <svg>…</svg>
        </x-panel.confirm-action>

    Los atributos sueltos (`class`, `x-show`…) van al botón, así que la fila conserva el aspecto que
    ya tenía. El globo de ayuda va en `tooltip` y no en `title`, porque `title` ya es el titular del
    diálogo.
--}}
@props([
    'action',
    'method' => 'DELETE',
    'title',
    'message' => null,
    'note' => null,
    'irreversible' => false,
    'confirm' => 'Eliminar',
    'tone' => 'danger',
    'requireText' => null,
    'tooltip' => null,
])

@php
    // El identificador sale de la ruta, que ya lleva el id del registro: dos botones de la misma
    // fila apuntan a acciones distintas, así que no chocan. Determinista a propósito, para que un
    // test pueda encontrar el formulario.
    $formId = 'cfm_'.substr(sha1($action.'|'.$method), 0, 12);
@endphp

<form id="{{ $formId }}" method="POST" action="{{ $action }}" class="hidden">
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif
</form>

<button type="button"
        @if ($tooltip) title="{{ $tooltip }}" @endif
        {{ $attributes->merge(['class' => 'cursor-pointer']) }}
        onclick="window.confirmarAccion({{ Js::from(array_filter([
            'titulo' => $title,
            'mensaje' => $message,
            'aviso' => $note,
            'avisoGrave' => (bool) $irreversible,
            'confirmar' => $confirm,
            'tono' => $tone === 'danger' ? 'peligro' : 'neutro',
            'exigirTexto' => $requireText,
            'formulario' => $formId,
        ], static fn ($valor) => $valor !== null && $valor !== false)) }})">
    {{ $slot }}
</button>
