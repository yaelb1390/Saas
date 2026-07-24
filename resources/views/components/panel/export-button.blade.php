@props(['route'])

<div x-data="{ open: false }" class="relative">
    <button type="button" @click="open = !open"
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-indigo-600">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/>
        </svg>
        Exportar
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5" :class="open ? 'rotate-180' : ''">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
        </svg>
    </button>

    <div x-show="open" x-cloak @click.outside="open = false" x-transition
         class="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
        <a href="{{ route($route, request()->query()) }}"
           class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
            <span class="w-9 text-xs font-bold text-slate-400">CSV</span> Valores
        </a>
        <a href="{{ route($route, array_merge(request()->query(), ['format' => 'xlsx'])) }}"
           class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
            <span class="w-9 text-xs font-bold text-emerald-600">XLSX</span> Excel
        </a>
    </div>
</div>
