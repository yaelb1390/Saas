{{--
    Zona para elegir archivos.

    Sustituye al `<input type="file">` pelado, que el navegador pinta con su propio botón gris y el
    texto «Sin archivos seleccionados»: dentro de un panel con sus campos y sus botones, ese control
    canta como de otra época y encima no dice qué se ha elegido hasta que se mira muy de cerca.

    El input de verdad SIGUE AHÍ, solo que oculto: es él quien viaja en el envío, así que el
    servidor no se entera de nada y no hay que tocar ninguna ruta. Lo que se ve es una etiqueta
    `<label for>` asociada a él, de modo que pulsar abre el diálogo por el camino nativo —sin
    JavaScript de por medio— y el teclado y los lectores de pantalla siguen funcionando igual.

    Arrastrar y soltar es el añadido: los archivos soltados se le PASAN al input, de forma que
    arrastrar y elegir a mano acaban exactamente en el mismo sitio.

    Uso:
        <x-panel.zona-archivos name="photos[]" label="Añadir fotos" accept="image/*" multiple
                               unidad="foto" genero="f" hint="Se recortan solas…" />
--}}
@props([
    'name',
    'label' => null,
    'accept' => null,
    'multiple' => false,
    'hint' => null,
    'unidad' => 'archivo',
    // El género no se puede deducir de la palabra —«foto» acaba en o y es femenina—, así que se
    // declara. Sin esto salían concordancias como «3 fotos elegidos».
    'genero' => 'm',
])

@php
    // Identificador propio por instancia: en la ficha hay dos zonas en la misma página y un `for`
    // repetido haría que la segunda abriera el diálogo de la primera.
    $id = 'zona-'.\Illuminate\Support\Str::random(8);

    $femenino = $genero === 'f';
    $plural = \Illuminate\Support\Str::plural($unidad);
    $nombre = $multiple ? $plural : $unidad;

    $articulo = $femenino
        ? ($multiple ? 'las' : 'la')
        : ($multiple ? 'los' : 'el');

    // El pronombre que se pega a «elegir»: elegirlo / elegirla / elegirlos / elegirlas.
    $pronombre = $femenino
        ? ($multiple ? 'las' : 'la')
        : ($multiple ? 'los' : 'lo');

    $invitacion = "Arrastra {$articulo} {$nombre} o pulsa para elegir{$pronombre}";
    $resumenVarios = $plural.' '.($femenino ? 'elegidas' : 'elegidos');
@endphp

<div x-data="{
        archivos: [],
        dentro: false,

        get hay() { return this.archivos.length > 0; },

        get resumen() {
            if (this.archivos.length === 1) return this.archivos[0];

            return this.archivos.length + ' {{ $resumenVarios }}';
        },

        recoger(lista) {
            this.archivos = Array.from(lista).map((f) => f.name);
        },

        soltar(evento) {
            this.dentro = false;

            const campo = this.$refs.campo;
            let lista = evento.dataTransfer.files;

            // Si el campo admite uno solo y sueltan varios, se queda el primero en vez de que el
            // navegador decida por su cuenta.
            if (!campo.multiple && lista.length > 1) {
                const dt = new DataTransfer();
                dt.items.add(lista[0]);
                lista = dt.files;
            }

            campo.files = lista;
            this.recoger(campo.files);
        },

        limpiar() {
            this.$refs.campo.value = '';
            this.archivos = [];
        },
     }">

    @if ($label)
        <label class="bmos-field-label" for="{{ $id }}">{{ $label }}</label>
    @endif

    <label for="{{ $id }}" class="bmos-zona"
           :class="{ 'is-dentro': dentro, 'is-lleno': hay }"
           @dragover.prevent="dentro = true"
           @dragleave.prevent="dentro = false"
           @drop.prevent="soltar($event)">

        <span class="bmos-zona-icono">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z"/>
            </svg>
        </span>

        <span class="bmos-zona-texto">
            <b x-show="!hay">{{ $invitacion }}</b>
            {{-- El nombre de lo elegido, en su sitio: antes había que abrir el diálogo otra vez
                 para recordar qué se había puesto. --}}
            <b x-show="hay" x-cloak x-text="resumen" class="bmos-zona-elegido"></b>
            @if ($hint)
                <small>{{ $hint }}</small>
            @endif
        </span>

        <input type="file" id="{{ $id }}" name="{{ $name }}" x-ref="campo"
               @if ($accept) accept="{{ $accept }}" @endif
               @if ($multiple) multiple @endif
               {{ $attributes->merge(['class' => 'bmos-zona-campo']) }}
               @change="recoger($event.target.files)">
    </label>

    {{-- Fuera de la etiqueta a propósito: dentro, pulsarlo abriría también el diálogo de archivos. --}}
    <button type="button" x-show="hay" x-cloak @click="limpiar()" class="bmos-zona-quitar">
        Quitar selección
    </button>
</div>
