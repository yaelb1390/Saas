@use('App\Modules\Core\Support\ModuleRegistry')

<x-layouts.admin title="Ayuda" heading="¿En qué te ayudo?"
                 subheading="Pregunta cómo se hace algo y te digo dónde y qué pasa después">
    <div class="mx-auto max-w-4xl">

        <form method="GET" action="{{ route('panel.help') }}" class="relative">
            <input type="search" name="q" value="{{ $pregunta }}" autofocus autocomplete="off"
                   placeholder="Por ejemplo: cómo anulo una venta"
                   class="bmos-input w-full py-4 pl-12 pr-28 text-lg">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
            </svg>
            <button type="submit" class="bmos-btn bmos-btn-primary absolute right-2 top-1/2 -translate-y-1/2">Preguntar</button>
        </form>

        @if ($respuesta === null)
            {{-- Delante de una caja vacía la gente no sabe qué escribir, y si la primera pregunta no
                 encuentra nada, no hay segunda. --}}
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($sugerencias as $s)
                    <a href="{{ route('panel.help', ['q' => $s]) }}"
                       class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-600 transition hover:border-indigo-300 hover:text-indigo-600">
                        {{ $s }}
                    </a>
                @endforeach
            </div>

            <div class="mt-8 space-y-6">
                @forelse ($indice as $modulo => $articulos)
                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                            {{ $modulo === 'general' ? 'General' : (ModuleRegistry::all()[$modulo] ?? $modulo) }}
                        </p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($articulos as $a)
                                <a href="{{ route('panel.help.article', $a->slug) }}"
                                   class="bmos-card block p-3 transition hover:shadow-md">
                                    <p class="font-medium text-slate-800">{{ $a->title }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $a->excerpt(90) }}</p>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="bmos-empty">Todavía no hay artículos de ayuda.</p>
                @endforelse
            </div>
        @else
            @if ($respuesta['article'] === null)
                {{-- No se inventa nada: si el manual no lo dice, se dice que no se sabe. --}}
                <div class="mt-8 bmos-card bmos-card-pad text-center">
                    <p class="text-lg font-semibold text-slate-800">No encuentro nada sobre eso</p>
                    <p class="mt-2 text-sm text-slate-500">
                        Prueba con otras palabras, o mira el índice completo.
                    </p>
                    <a href="{{ route('panel.help') }}" class="bmos-btn bmos-btn-ghost mt-4">Ver todos los temas</a>
                </div>
            @else
                @if ($respuesta['answer'] !== null)
                    <div class="mt-6 bmos-card bmos-card-pad border-l-4 border-indigo-500">
                        <div class="bmos-prose">{!! Str::markdown($respuesta['answer']) !!}</div>
                        <p class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-400">
                            Respuesta redactada a partir de
                            <a href="{{ route('panel.help.article', $respuesta['article']->slug) }}"
                               class="text-indigo-600 hover:underline">{{ $respuesta['article']->title }}</a>.
                        </p>
                    </div>
                @endif

                {{-- El artículo va SIEMPRE, con o sin IA: es la respuesta comprobable, y sin clave de
                     API es la única. --}}
                <div class="mt-6 bmos-card bmos-card-pad">
                    @if ($respuesta['answer'] === null)
                        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Esto es lo que responde a tu pregunta</p>
                    @endif
                    <h2 class="text-xl font-bold text-slate-800">{{ $respuesta['article']->title }}</h2>
                    <div class="bmos-prose mt-3">{!! Str::markdown($respuesta['article']->body) !!}</div>

                    @if ($respuesta['article']->route)
                        <a href="{{ route($respuesta['article']->route) }}" class="bmos-btn bmos-btn-primary mt-5">
                            Ir a la pantalla →
                        </a>
                    @endif
                </div>

                @if ($respuesta['related']->isNotEmpty())
                    <div class="mt-6">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">También puede interesarte</p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($respuesta['related'] as $a)
                                <a href="{{ route('panel.help.article', $a->slug) }}"
                                   class="bmos-card block p-3 transition hover:shadow-md">
                                    <p class="font-medium text-slate-800">{{ $a->title }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $a->excerpt(90) }}</p>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif

            <div class="mt-6 text-center">
                <a href="{{ route('panel.help') }}" class="text-sm text-indigo-600 hover:underline">Ver todos los temas</a>
            </div>
        @endif
    </div>
</x-layouts.admin>
