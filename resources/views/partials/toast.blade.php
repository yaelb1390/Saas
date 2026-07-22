{{-- Aviso flotante de resultado. Aparece arriba, se cierra solo a los 4 s y se puede cerrar a mano.
     Reúne los mensajes de éxito (panel_ok / pos_ok) y de error (panel_error / pos_error) de todo el
     sistema, más un aviso si hubo errores de validación. Así toda acción que guarda algo da un
     acuse claro —«Registro exitoso»— y ningún fallo pasa desapercibido. --}}
@php
    // El POS tiene su propio aviso (con botón de recibo), así que aquí solo van los guardados de
    // formularios del panel, que es donde faltaba el acuse.
    $okMsg = session('panel_ok');
    // Mensaje de error concreto: primero el del servicio, y si fue validación, el PRIMER error real
    // (p. ej. «El código de barras ya ha sido tomado») en vez de un texto genérico. Así el usuario
    // sabe exactamente qué corregir.
    $errMsg = session('panel_error') ?? ($errors->any() ? $errors->first() : null);
@endphp

@if ($okMsg || $errMsg)
    {{-- Los errores no se cierran solos (más tiempo para leer el motivo); el éxito sí. --}}
    <div x-data="{ show: true }" x-show="show" x-cloak
         x-init="@if ($okMsg) setTimeout(() => show = false, 4500) @endif"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-end="opacity-0 -translate-y-2"
         class="fixed inset-x-0 top-4 z-[100] flex justify-center px-4"
         role="status" aria-live="polite">

        @if ($okMsg)
            <div class="flex w-full max-w-md items-start gap-3 rounded-xl border border-emerald-200 bg-white px-4 py-3 shadow-lg">
                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-emerald-700">Registro exitoso</p>
                    <p class="text-sm text-slate-600">{{ $okMsg }}</p>
                </div>
                <button type="button" @click="show = false" class="text-slate-400 hover:text-slate-600">&times;</button>
            </div>
        @else
            <div class="flex w-full max-w-md items-start gap-3 rounded-xl border border-rose-200 bg-white px-4 py-3 shadow-lg">
                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.01M12 3l9 16H3l9-16Z"/>
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-rose-700">No se pudo guardar</p>
                    <p class="text-sm text-slate-600">
                        {{ $errMsg ?? 'Revisa los datos marcados en el formulario e inténtalo de nuevo.' }}
                    </p>
                </div>
                <button type="button" @click="show = false" class="text-slate-400 hover:text-slate-600">&times;</button>
            </div>
        @endif
    </div>
@endif
