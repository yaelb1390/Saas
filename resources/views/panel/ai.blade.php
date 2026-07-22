@php
    $sentBadge = fn ($s) => match ($s->value) {
        'positive' => 'badge-green', 'negative' => 'badge-red', default => 'badge-gray',
    };
    $aiAnswer = session('ai_answer');
    $aiQuery = session('ai_query');
    $aiSources = session('ai_sources', []);
@endphp
<x-layouts.admin title="IA & RAG" heading="Inteligencia Artificial" subheading="Asistente sobre tu base de conocimiento y análisis de sentimiento">

    {{-- Asistente RAG --}}
    <div class="bmos-card overflow-hidden">
        <div class="border-b border-slate-100 bg-gradient-to-r from-violet-50 to-indigo-50 p-5">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-600 text-white shadow-sm">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-6 w-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"/></svg>
                </span>
                <div>
                    <p class="font-semibold text-slate-800">Asistente de conocimiento</p>
                    <p class="text-sm text-slate-500">Pregunta en lenguaje natural; responde usando tus documentos indexados y cita las fuentes.</p>
                </div>
            </div>
        </div>

        <div class="p-5">
            <form method="POST" action="{{ route('panel.ai.ask') }}" class="flex flex-col gap-2 sm:flex-row">
                @csrf
                <input type="text" name="query" value="{{ old('query', $aiQuery) }}" required minlength="3"
                       placeholder="Ej. ¿Cuál es la política de devoluciones?"
                       class="bmos-input flex-1" autocomplete="off">
                <button type="submit" class="bmos-btn bmos-btn-primary whitespace-nowrap">Preguntar</button>
            </form>
            @error('query')
                <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
            @enderror

            @if ($aiAnswer !== null)
                <div class="mt-5 space-y-4">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Pregunta</p>
                        <p class="text-sm font-medium text-slate-700">{{ $aiQuery }}</p>
                        <p class="mb-1 mt-4 text-xs font-semibold uppercase tracking-wide text-violet-500">Respuesta</p>
                        <p class="whitespace-pre-line text-sm leading-relaxed text-slate-800">{{ $aiAnswer }}</p>
                    </div>

                    @if (! empty($aiSources))
                        <div>
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Fuentes utilizadas</p>
                            <div class="space-y-2">
                                @foreach ($aiSources as $src)
                                    <div class="rounded-lg border border-slate-100 bg-white p-3">
                                        <div class="mb-1 flex items-center justify-between gap-2">
                                            <span class="text-sm font-semibold text-slate-700">{{ $src['title'] }}</span>
                                            <span class="bmos-badge badge-violet">{{ number_format($src['similarity'] * 100, 0) }}% afín</span>
                                        </div>
                                        <p class="text-xs text-slate-500">{{ $src['excerpt'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <p class="text-sm text-amber-600">No había documentos relevantes en la base de conocimiento. Indexa contenido para obtener respuestas fundamentadas.</p>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-2">
        {{-- Base de conocimiento --}}
        <div class="bmos-card overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-100 p-4">
                <p class="font-semibold text-slate-800">Base de conocimiento (RAG)</p>
                @can('ai.documents.manage')
                <x-panel.create-modal title="Indexar documento" label="Documento" form="ai_document" :action="route('panel.ai.documents.store')">
                    <x-panel.field name="title" label="Título" required placeholder="Política de devoluciones" />
                    <x-panel.field name="source" label="Fuente (opcional)" placeholder="Manual interno, URL..." />
                    <div>
                        <label class="bmos-field-label">Contenido</label>
                        <textarea name="content" rows="6" required minlength="10" class="bmos-input"
                                  placeholder="Pega aquí el texto que quieres que la IA use para responder...">{{ old('content') }}</textarea>
                    </div>
                </x-panel.create-modal>
                @endcan
            </div>
            <div class="divide-y divide-slate-100">
                @forelse ($documents as $doc)
                    <div class="flex items-center justify-between p-4">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 text-violet-600">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-5 w-5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                                </svg>
                            </span>
                            <div>
                                <p class="font-medium text-slate-800">{{ $doc->title }}</p>
                                <p class="text-xs text-slate-400">{{ $doc->source ?? 'sin fuente' }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="bmos-badge badge-violet">{{ $doc->chunks_count }} chunks</span>
                            @can('ai.documents.manage')
                            <form method="POST" action="{{ route('panel.ai.documents.destroy', $doc) }}" onsubmit="return confirm('¿Eliminar «{{ $doc->title }}» de la base de conocimiento?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Eliminar">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.1rem;height:1.1rem"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916"/></svg>
                                </button>
                            </form>
                            @endcan
                        </div>
                    </div>
                @empty
                    <p class="bmos-empty">Sin documentos en la base de conocimiento.</p>
                @endforelse
            </div>
        </div>

        {{-- Análisis de sentimiento --}}
        <div class="bmos-card overflow-hidden">
            <div class="border-b border-slate-100 p-4"><p class="font-semibold text-slate-800">Análisis de sentimiento</p></div>
            <div class="divide-y divide-slate-100">
                @forelse ($sentiments as $s)
                    <div class="flex items-center justify-between p-4">
                        <div>
                            <p class="text-sm text-slate-600">{{ class_basename($s->analyzable_type) }} #{{ $s->analyzable_id }}</p>
                            <p class="text-xs text-slate-400">score {{ number_format((float) $s->score, 2) }} · {{ $s->model }}</p>
                        </div>
                        <span class="bmos-badge {{ $sentBadge($s->sentiment) }}">{{ $s->sentiment->label() }}</span>
                    </div>
                @empty
                    <p class="bmos-empty">Aún no hay análisis de sentimiento.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-layouts.admin>
