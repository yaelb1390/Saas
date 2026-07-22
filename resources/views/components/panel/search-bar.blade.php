@props(['placeholder' => 'Buscar...'])

<form method="GET" class="flex items-center gap-2">
    <div class="relative">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
             class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400">
            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
        </svg>
        <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ $placeholder }}"
               class="bmos-input" style="padding-left:2.1rem;min-width:210px">
    </div>
    @if (request('q'))
        <a href="{{ url()->current() }}" class="bmos-btn bmos-btn-ghost text-sm">Limpiar</a>
    @endif
</form>
