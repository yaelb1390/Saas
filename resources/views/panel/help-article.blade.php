<x-layouts.admin :title="$articulo->title" :heading="$articulo->title" subheading="Ayuda">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('panel.help') }}" class="text-sm text-indigo-600 hover:underline">← Volver a la ayuda</a>

        <div class="mt-4 bmos-card bmos-card-pad">
            <div class="bmos-prose">{!! Str::markdown($articulo->body) !!}</div>

            @if ($articulo->route)
                <a href="{{ route($articulo->route) }}" class="bmos-btn bmos-btn-primary mt-6">
                    Ir a la pantalla →
                </a>
            @endif
        </div>

        @if ($relacionados->isNotEmpty())
            <div class="mt-6">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Relacionado</p>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($relacionados as $a)
                        <a href="{{ route('panel.help.article', $a->slug) }}"
                           class="bmos-card block p-3 transition hover:shadow-md">
                            <p class="font-medium text-slate-800">{{ $a->title }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $a->excerpt(90) }}</p>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layouts.admin>
