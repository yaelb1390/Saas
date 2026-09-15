{{--
    Campos del checklist de entrega/devolución. Un solo parcial para las dos, porque piden lo mismo.

    `prefijo` solo evita que dos canvas de firma en la misma página (entrega y devolución) compartan
    id si algún día conviven en el DOM a la vez.
--}}
@props(['prefijo'])

@php
    $items = [
        'carroceria' => 'Carrocería', 'cristales' => 'Cristales', 'neumaticos' => 'Neumáticos',
        'luces' => 'Luces', 'interior' => 'Interior', 'aire' => 'Aire acondicionado',
        'radio' => 'Radio', 'documentos' => 'Documentos', 'accesorios' => 'Accesorios',
    ];
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <x-panel.field name="mileage" label="Kilometraje" type="number" required />
    <div>
        <label class="bmos-field-label">Combustible <span class="text-rose-500">&nbsp;*</span></label>
        <select name="fuel_level" required class="bmos-input">
            <option value="full">Lleno</option>
            <option value="three_quarters">3/4</option>
            <option value="half">1/2</option>
            <option value="quarter">1/4</option>
            <option value="empty">Vacío</option>
        </select>
    </div>
</div>

<div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
    <x-panel.field name="exterior_condition" label="Estado exterior" placeholder="Sin golpes visibles…" />
    <x-panel.field name="interior_condition" label="Estado interior" placeholder="Limpio, sin manchas…" />
</div>

<x-panel.field name="accessories" label="Accesorios (llantas de repuesto, gato, cargador…)" class="mt-3" />

<div class="mt-3">
    <p class="bmos-field-label">Revisión</p>
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
        @foreach ($items as $clave => $etiqueta)
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="checklist[{{ $clave }}]" value="1" checked class="rounded border-slate-300 text-indigo-600">
                {{ $etiqueta }}
            </label>
        @endforeach
    </div>
</div>

<div class="mt-3">
    <label class="bmos-field-label">Observaciones</label>
    <textarea name="observations" rows="2" class="bmos-input"></textarea>
</div>

<div class="mt-3">
    <label class="bmos-field-label">Fotos (opcional)</label>
    <input type="file" name="photos[]" accept="image/*" multiple
           class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-indigo-600 hover:file:bg-indigo-100">
</div>

{{-- Firma del cliente, capturada a mano alzada en un lienzo. Sin librería nueva: son unas líneas de
     canvas y eventos de puntero, y evita instalar algo solo para esto. --}}
<div class="mt-3" x-data="firmaLienzo('{{ $prefijo }}')" x-init="init()">
    <label class="bmos-field-label">Firma del cliente (opcional)</label>
    <canvas x-ref="lienzo" width="500" height="150"
            class="w-full touch-none rounded-lg border border-slate-200 bg-white"
            @pointerdown="empezar" @pointermove="dibujar" @pointerup="terminar" @pointerleave="terminar"></canvas>
    <button type="button" @click="limpiar" class="mt-1 text-xs text-slate-400 hover:text-slate-600">Borrar firma</button>
    <input type="hidden" name="signature" x-ref="entrada">
</div>

{{-- Esta plantilla se incluye hasta DOS veces en la misma página (entrega y devolución), así que la
     función se declara una sola vez en `window` — declararla suelta con `function` chocaría con un
     «no se puede redeclarar» la segunda vez. --}}
<script>
    window.firmaLienzo ??= function () {
        return {
            dibujando: false,
            ctx: null,
            init() {
                this.ctx = this.$refs.lienzo.getContext('2d');
                this.ctx.strokeStyle = '#1e293b';
                this.ctx.lineWidth = 2;
                this.ctx.lineJoin = 'round';
                this.ctx.lineCap = 'round';
            },
            posicion(event) {
                const r = this.$refs.lienzo.getBoundingClientRect();
                const escalaX = this.$refs.lienzo.width / r.width;
                const escalaY = this.$refs.lienzo.height / r.height;

                return { x: (event.clientX - r.left) * escalaX, y: (event.clientY - r.top) * escalaY };
            },
            empezar(event) {
                this.dibujando = true;
                const p = this.posicion(event);
                this.ctx.beginPath();
                this.ctx.moveTo(p.x, p.y);
            },
            dibujar(event) {
                if (!this.dibujando) return;
                const p = this.posicion(event);
                this.ctx.lineTo(p.x, p.y);
                this.ctx.stroke();
            },
            terminar() {
                if (!this.dibujando) return;
                this.dibujando = false;
                this.$refs.entrada.value = this.$refs.lienzo.toDataURL('image/png');
            },
            limpiar() {
                this.ctx.clearRect(0, 0, this.$refs.lienzo.width, this.$refs.lienzo.height);
                this.$refs.entrada.value = '';
            },
        };
    };
</script>
