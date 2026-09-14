{{--
    Los campos de una impresora: sirve para el alta y para la edición.

    `$printer` es null en el alta (se muestra en blanco) y el modelo en la edición (se precargan sus
    valores). `old()` gana sobre los dos si el propio formulario falló una validación, que es el
    comportamiento normal de Laravel.

    Es donde vive la «Configuración de impresora» del spec (tipo, ancho de papel, márgenes,
    orientación, calidad, copias, corte automático, color): va junto al registro y no en una pantalla
    aparte porque son datos DE LA MISMA impresora — separar «qué impresora es» de «cómo se configura»
    en dos pantallas distintas solo obligaría a abrir dos sitios para una sola ficha.
--}}
@php
    $s = $printer?->settings ?? [];
@endphp
<div x-data="{ connType: @js(old('connection_type', $printer?->connection_type?->value ?? 'browser')), paperSize: @js(old('paper_size', $printer?->paper_size ?? '80mm')) }">
    <x-panel.field name="name" label="Nombre" required placeholder="Térmica de la caja 1" :value="$printer?->name" />

    <div class="mt-3 grid grid-cols-2 gap-3">
        <x-panel.field name="manufacturer" label="Fabricante (opcional)" placeholder="Epson, Xprinter…" :value="$printer?->manufacturer" />
        <x-panel.field name="model" label="Modelo (opcional)" placeholder="TM-T20" :value="$printer?->model" />
    </div>

    <div class="mt-3">
        <label class="bmos-field-label">Tipo de conexión</label>
        <select name="connection_type" x-model="connType" class="bmos-input">
            @foreach (\App\Modules\Printing\Enums\ConnectionType::cases() as $tipo)
                <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
            @endforeach
        </select>
    </div>

    {{-- Bluetooth: normalmente estos tres los llena la pestaña Bluetooth al emparejar. Se dejan
         editables aquí para el caso avanzado de una impresora que necesita un UUID distinto al
         habitual (ver `resources/js/printing/bluetooth.js`). --}}
    <div x-show="connType === 'bluetooth'" x-cloak class="mt-3 space-y-3 rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="text-xs text-slate-500">Estos datos normalmente se completan solos al emparejar desde la pestaña «Bluetooth».</p>
        <x-panel.field name="bt_device_id" label="Id. del dispositivo" :value="$printer?->bt_device_id" />
        <div class="grid grid-cols-2 gap-3">
            <x-panel.field name="bt_service_uuid" label="UUID de servicio" :value="$printer?->bt_service_uuid" />
            <x-panel.field name="bt_characteristic_uuid" label="UUID de característica" :value="$printer?->bt_characteristic_uuid" />
        </div>
    </div>

    <div x-show="connType === 'network'" x-cloak class="mt-3">
        <x-panel.field name="address" label="Dirección (IP:puerto)" placeholder="192.168.1.50:9100" :value="$printer?->address" />
        <p class="mt-1 text-xs text-slate-400">Una página web no puede buscar impresoras en la red: se escribe a mano la dirección que ya tiene en tu router o en su propia pantalla.</p>
    </div>

    <div class="mt-3">
        <label class="bmos-field-label">Tamaño de papel</label>
        <select name="paper_size" x-model="paperSize" class="bmos-input">
            @foreach (\App\Modules\Printing\Support\PaperSize::options() as $clave => $etiqueta)
                <option value="{{ $clave }}">{{ $etiqueta }}</option>
            @endforeach
        </select>
    </div>
    <div x-show="paperSize === 'custom'" x-cloak class="mt-3 grid grid-cols-2 gap-3">
        <x-panel.field name="custom_width_mm" label="Ancho (mm)" type="number" min="1" :value="$printer?->custom_width_mm" />
        <x-panel.field name="custom_height_mm" label="Alto (mm, vacío = rollo continuo)" type="number" min="1" :value="$printer?->custom_height_mm" />
    </div>

    <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">Impresión</p>
    <div class="mt-2 grid grid-cols-2 gap-3">
        <div>
            <label class="bmos-field-label">Orientación</label>
            <select name="settings[orientation]" class="bmos-input">
                <option value="portrait" @selected(old('settings.orientation', $s['orientation'] ?? 'portrait') === 'portrait')>Vertical</option>
                <option value="landscape" @selected(old('settings.orientation', $s['orientation'] ?? 'portrait') === 'landscape')>Horizontal</option>
            </select>
        </div>
        <div>
            <label class="bmos-field-label">Calidad</label>
            <select name="settings[quality]" class="bmos-input">
                <option value="draft" @selected(old('settings.quality', $s['quality'] ?? 'normal') === 'draft')>Borrador</option>
                <option value="normal" @selected(old('settings.quality', $s['quality'] ?? 'normal') === 'normal')>Normal</option>
                <option value="high" @selected(old('settings.quality', $s['quality'] ?? 'normal') === 'high')>Alta</option>
            </select>
        </div>
    </div>
    <div class="mt-3 grid grid-cols-2 gap-3">
        <x-panel.field name="settings[margin_mm]" label="Margen (mm)" type="number" step="0.5" min="0" :value="old('settings.margin_mm', $s['margin_mm'] ?? 2)" />
        <x-panel.field name="settings[default_copies]" label="Copias por defecto" type="number" min="1" max="20" :value="old('settings.default_copies', $s['default_copies'] ?? 1)" />
    </div>
    <div class="mt-3 grid grid-cols-2 gap-3">
        <div>
            <label class="bmos-field-label">Color</label>
            <select name="settings[color]" class="bmos-input">
                <option value="bw" @selected(old('settings.color', $s['color'] ?? 'bw') === 'bw')>Blanco y negro</option>
                <option value="color" @selected(old('settings.color', $s['color'] ?? 'bw') === 'color')>Color</option>
            </select>
        </div>
        <label class="mt-6 flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="settings[auto_cut]" value="0">
            <input type="checkbox" name="settings[auto_cut]" value="1" class="rounded border-slate-300 text-indigo-600"
                   @checked(old('settings.auto_cut', $s['auto_cut'] ?? true))>
            Corte automático
        </label>
    </div>
</div>
