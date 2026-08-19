{{--
    Los ajustes de IA de la plataforma.

    Una sola clave para todas las empresas. Un dueño de colmado no va a sacar una clave en Google AI
    Studio, y exigírselo dejaría el módulo apagado para todo el mundo — con Zernio ya se comprobó.

    La clave NUNCA se devuelve al HTML: el campo va vacío siempre y, si se envía vacío, se conserva
    la que había.
--}}
@php
    $proveedores = [
        'gemini' => ['Gemini (Google)', 'Hace las dos cosas: redacta y también indexa documentos. Es el recomendado.'],
        'openai' => ['OpenAI', 'Redacta e indexa. Se paga por uso desde el primer día.'],
        'anthropic' => ['Claude (Anthropic)', 'Solo redacta: NO puede indexar documentos, así que el asistente no encontrará nada.'],
        'local' => ['Ninguno (apagado)', 'Sin proveedor. El asistente no redacta y el sentimiento usa el clasificador por palabras.'],
    ];
@endphp

<x-layouts.admin title="IA de la plataforma" heading="Inteligencia Artificial"
                 subheading="La clave con la que funciona el asistente de todas las empresas">
    <div class="mx-auto max-w-3xl space-y-5">
        {{-- El estado, antes que nada: es lo que se viene a mirar. --}}
        <div class="bmos-cifras">
            <div class="bmos-cifra" data-tono="{{ $ajustes->configurado() ? 'tone-emerald' : 'es-neutra' }}">
                <p class="bmos-cifra-valor">{{ $ajustes->configurado() ? 'Encendida' : 'Apagada' }}</p>
                <p class="bmos-cifra-etq">
                    {{ $ajustes->configurado() ? ($proveedores[$ajustes->provider][0] ?? $ajustes->provider) : 'sin clave' }}
                </p>
            </div>
            <div class="bmos-cifra" data-tono="tone-indigo">
                <p class="bmos-cifra-valor">{{ $ajustes->embedding_dimensions }}</p>
                <p class="bmos-cifra-etq">dimensiones del vector</p>
            </div>
            <div class="bmos-cifra" data-tono="{{ $desfasados > 0 ? 'tone-amber' : 'tone-emerald' }}">
                <p class="bmos-cifra-valor">{{ number_format($desfasados) }}</p>
                <p class="bmos-cifra-etq">{{ $desfasados > 0 ? 'fragmentos por reindexar' : 'todo al día' }}</p>
            </div>
        </div>

        {{-- Los documentos indexados con otro proveedor NO se usan, y hay que decirlo: si no, el
             asistente contestaría «no encontré nada» sin que nadie pudiera saber por qué. --}}
        @if ($desfasados > 0)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="font-semibold text-amber-900">Hay documentos indexados con otro proveedor</p>
                <p class="mt-1 text-sm text-amber-800">
                    Cada proveedor genera vectores de un espacio distinto, así que no se pueden mezclar:
                    mientras no se reindexen, el asistente <b>no los usa</b>. Se hace por tandas porque
                    reindexarlos todos de golpe agotaría el tiempo de la petición.
                </p>
                <form method="POST" action="{{ route('platform.ai.reindex') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="bmos-btn bmos-btn-primary">Reindexar una tanda</button>
                </form>
            </div>
        @endif

        <div class="bmos-card bmos-card-pad">
            <form method="POST" action="{{ route('platform.ai.update') }}" class="space-y-4"
                  x-data="{ proveedor: @js($ajustes->provider) }">
                @csrf
                @method('PUT')

                <div>
                    <label class="bmos-field-label">Proveedor</label>
                    <select name="provider" class="bmos-input" x-model="proveedor">
                        @foreach ($proveedores as $clave => $datos)
                            <option value="{{ $clave }}" @selected($ajustes->provider === $clave)>{{ $datos[0] }}</option>
                        @endforeach
                    </select>
                    @foreach ($proveedores as $clave => $datos)
                        <p class="mt-1 text-xs {{ $clave === 'anthropic' ? 'text-amber-600' : 'text-slate-400' }}"
                           x-show="proveedor === '{{ $clave }}'" x-cloak>{{ $datos[1] }}</p>
                    @endforeach
                </div>

                <div x-show="proveedor !== 'local'" x-cloak>
                    <label class="bmos-field-label">Clave de API</label>
                    {{-- Vacío = se conserva la que ya había. La clave no se devuelve nunca al HTML. --}}
                    <input type="password" name="api_key" class="bmos-input font-mono" autocomplete="off"
                           placeholder="{{ filled($ajustes->api_key) ? 'Ya hay una guardada — déjalo vacío para conservarla' : 'Pega aquí la clave' }}">
                    <p class="mt-1 text-xs text-slate-400">
                        Se guarda cifrada y no se vuelve a mostrar.
                        <span x-show="proveedor === 'gemini'" x-cloak>Se saca gratis en <b>aistudio.google.com</b>.</span>
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3" x-show="proveedor !== 'local'" x-cloak>
                    <div>
                        <label class="bmos-field-label">Modelo de chat</label>
                        <input type="text" name="chat_model" class="bmos-input" value="{{ $ajustes->chat_model }}"
                               placeholder="gemini-2.0-flash">
                    </div>
                    <div x-show="proveedor !== 'anthropic'">
                        <label class="bmos-field-label">Modelo de embeddings</label>
                        <input type="text" name="embedding_model" class="bmos-input" value="{{ $ajustes->embedding_model }}"
                               placeholder="gemini-embedding-001">
                    </div>
                    <div x-show="proveedor !== 'anthropic'">
                        <label class="bmos-field-label">Dimensiones</label>
                        <input type="number" name="embedding_dimensions" class="bmos-input"
                               value="{{ $ajustes->embedding_dimensions }}" min="64" max="3072">
                    </div>
                </div>

                {{-- El tamaño se fija a propósito y no se hereda del modelo: si el proveedor cambiara
                     mañana lo que devuelve por omisión, todo lo indexado dejaría de casar en silencio. --}}
                <p class="text-xs text-slate-400" x-show="proveedor !== 'local' && proveedor !== 'anthropic'" x-cloak>
                    Cambiar las dimensiones o el proveedor obliga a reindexar todos los documentos.
                </p>

                {{-- El tope del asistente de ayuda.

                     Va aquí, con los ajustes de IA, porque es lo mismo: cuánto estás dispuesto a
                     gastar. Y es un solo número para toda la plataforma a propósito: un tope por
                     empresa serían quince sitios donde mirar cuando alguien diga «se me acabó». --}}
                <div class="border-t border-slate-100 pt-4">
                    <label class="bmos-field-label">Preguntas al día por empresa</label>
                    <input type="number" name="daily_limit" class="bmos-input" style="max-width:10rem"
                           value="{{ $ajustes->daily_limit }}" min="0" max="1000">
                    <p class="mt-1 text-xs text-slate-400">
                        Cuántas veces al día puede preguntarle cada empresa al asistente de ayuda.
                        Cada pregunta la pagas tú en el proveedor. <b>Cero lo apaga para todas.</b>
                    </p>
                </div>

                <div class="border-t border-slate-100 pt-4">
                    <button type="submit" class="bmos-btn bmos-btn-primary">Guardar</button>
                </div>
            </form>

            {{-- Probar va en su PROPIO formulario: uno dentro de otro es HTML inválido y el navegador
                 desmonta el interior, rompiendo el envío del exterior. Ya pasó en «Mi empresa». --}}
            @if ($ajustes->configurado())
                <form method="POST" action="{{ route('platform.ai.test') }}" class="mt-3 border-t border-slate-100 pt-4">
                    @csrf
                    <button type="submit" class="bmos-btn bmos-btn-ghost">Probar la conexión</button>
                    <span class="ml-2 text-xs text-slate-400">
                        Hace una llamada de verdad y dice el motivo exacto si falla.
                    </span>
                </form>
            @endif
        </div>
    </div>
</x-layouts.admin>
