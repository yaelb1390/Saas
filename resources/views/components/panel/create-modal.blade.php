@props(['title', 'action', 'label' => null, 'form' => 'create'])

{{-- Modal de alta reutilizable. Se reabre solo si su propio formulario tuvo errores de validación. --}}
<div x-data="{ open: {{ old('_form') === $form ? 'true' : 'false' }} }" class="inline-block">
    <button type="button" @click="open = true" class="bmos-btn bmos-btn-primary">
        + {{ $label ?? $title }}
    </button>

    <div x-show="open" x-cloak
         class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10"
         @keydown.escape.window="open = false">
        <div @click.outside="open = false" x-transition
             class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-slate-800">{{ $title }}</h3>
                <button type="button" @click="open = false" class="text-slate-400 hover:text-slate-600">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            @if (old('_form') === $form && $errors->any())
                <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    <ul class="list-disc space-y-0.5 pl-4">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ $action }}" class="space-y-3">
                @csrf
                <input type="hidden" name="_form" value="{{ $form }}">
                {{ $slot }}
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="open = false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                    <button type="submit" class="bmos-btn bmos-btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
