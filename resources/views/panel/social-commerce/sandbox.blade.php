{{--
    Modo prueba: simula un comentario sin publicar nada ni tocar Zernio (prompt maestro, sección
    31). Es una aproximación local (ver KeywordMatcher) — la coincidencia real la decide Zernio.
--}}
<x-layouts.admin title="Probar una regla" heading="Probar una regla"
                 subheading="Escribe un comentario como lo escribiría un seguidor, y mira qué pasaría">
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('panel.social-commerce.index') }}" class="mb-4 inline-block text-sm text-indigo-600 hover:underline">
            ← Volver a Social Commerce
        </a>

        @if ($reglas->isEmpty())
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">No hay ninguna regla activa todavía</p>
                <p class="mt-2 text-sm text-slate-600">
                    <a href="{{ route('panel.social-commerce.create') }}" class="text-indigo-600 hover:underline">Crea una</a>
                    para poder probarla aquí.
                </p>
            </div>
        @else
            <div class="bmos-card bmos-card-pad"
                 x-data="{
                     comentario: '',
                     probando: false,
                     resultado: null,
                     async probar() {
                         if (! this.comentario.trim()) return;
                         this.probando = true;
                         this.resultado = null;
                         try {
                             const respuesta = await fetch(@js(route('panel.social-commerce.sandbox.simulate')), {
                                 method: 'POST',
                                 headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()), 'Accept': 'application/json' },
                                 body: JSON.stringify({ comentario: this.comentario }),
                             });
                             this.resultado = await respuesta.json();
                         } finally {
                             this.probando = false;
                         }
                     },
                 }">
                <label class="bmos-field-label">Comentario de prueba</label>
                <div class="flex gap-2">
                    <input type="text" x-model="comentario" @keydown.enter="probar()" class="bmos-input"
                           placeholder="¿Cuánto cuesta?">
                    <button type="button" @click="probar()" :disabled="probando" class="bmos-btn bmos-btn-primary shrink-0">
                        <span x-show="! probando">Probar</span>
                        <span x-show="probando" x-cloak>Probando…</span>
                    </button>
                </div>

                <div x-show="resultado" x-cloak class="mt-4 space-y-2">
                    <template x-for="(paso, i) in (resultado?.pasos ?? [])" :key="i">
                        <div class="flex items-start gap-2 text-sm">
                            <span x-text="paso.ok ? '✓' : '✕'" :class="paso.ok ? 'text-emerald-600' : 'text-rose-500'" class="font-bold"></span>
                            <span :class="paso.ok ? 'text-slate-700' : 'text-rose-600'" x-text="paso.texto"></span>
                        </div>
                    </template>
                </div>

                <p class="mt-4 text-xs text-slate-400">
                    Esto no publica nada ni avisa a nadie: es solo una simulación con tus reglas activas
                    ({{ $reglas->count() }} {{ $reglas->count() === 1 ? 'regla' : 'reglas' }}).
                </p>
            </div>
        @endif
    </div>
</x-layouts.admin>
