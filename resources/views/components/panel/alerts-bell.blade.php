@php
    $alerts = app(\App\Modules\Reports\Services\AlertService::class)->forCurrentCompany();
    $total = array_sum(array_column($alerts, 'count'));
    $tones = [
        'amber' => 'bg-amber-100 text-amber-600',
        'indigo' => 'bg-indigo-100 text-indigo-600',
        'sky' => 'bg-sky-100 text-sky-600',
    ];
@endphp

<div x-data="{ open: false }" class="relative">
    <button type="button" @click="open = !open" title="Alertas"
            class="relative flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
        </svg>
        @if ($total > 0)
            <span class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white">{{ $total > 99 ? '99+' : $total }}</span>
        @endif
    </button>

    <div x-show="open" @click.outside="open = false" x-transition x-cloak
         class="absolute right-0 z-30 mt-2 w-72 rounded-xl border border-slate-200 bg-white py-2 shadow-lg">
        <div class="px-4 py-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Alertas</div>
        @forelse ($alerts as $a)
            <a href="{{ $a['url'] }}" class="flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $tones[$a['tone']] ?? 'bg-slate-100 text-slate-600' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-4.5 w-4.5" style="width:1.15rem;height:1.15rem">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $a['icon'] }}"/>
                    </svg>
                </span>
                <span class="text-sm text-slate-700">{{ $a['title'] }}</span>
            </a>
        @empty
            <div class="px-4 py-3 text-sm text-slate-500">Todo en orden ✅</div>
        @endforelse
    </div>
</div>
