@use('App\Modules\Social\Enums\SocialPlatform')

{{--
    Conectar la cuenta de Instagram/Facebook (misma clave de Zernio que Redes sociales, pero un
    permiso propio: se puede tener Social Commerce sin tener contratado `social`) y el número de
    WhatsApp al que enlazan las plantillas con {url_whatsapp}.
--}}
<x-layouts.admin title="Ajustes de Social Commerce" heading="Ajustes de Social Commerce"
                 subheading="Conecta tu cuenta y el WhatsApp al que se enlazan tus respuestas">
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('panel.social-commerce.index') }}" class="mb-4 inline-block text-sm text-indigo-600 hover:underline">
            ← Volver a Social Commerce
        </a>

        @if ($aviso)
            <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-medium text-amber-900">{{ $aviso }}</p>
            </div>
        @endif

        <div class="bmos-card bmos-card-pad mb-5">
            <p class="font-semibold text-slate-800">Cuenta de Zernio</p>
            <p class="mt-1 text-sm text-slate-600">
                Es la misma clave que usa «Redes sociales» si ya la tienes contratada: una empresa, una cuenta.
            </p>

            @can('social_commerce.connect')
                <form method="PUT" action="{{ route('panel.social-commerce.settings.key') }}" class="mt-3 flex gap-2">
                    @csrf @method('PUT')
                    <input type="text" name="api_key" class="bmos-input" placeholder="sk_..." value="{{ $configurado ? '••••••••••••••••' : '' }}">
                    <button type="submit" class="bmos-btn bmos-btn-primary shrink-0">Guardar clave</button>
                </form>

                @if ($configurado)
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($plataformas as $red)
                            <form method="POST" action="{{ route('panel.social-commerce.connect') }}">
                                @csrf
                                <input type="hidden" name="platform" value="{{ $red->value }}">
                                <button type="submit" class="bmos-btn bmos-btn-ghost">Conectar {{ $red->label() }}</button>
                            </form>
                        @endforeach
                    </div>
                @endif
            @endcan

            @if ($cuentas !== [])
                <div class="mt-3 space-y-1">
                    @foreach ($cuentas as $c)
                        <p class="text-sm text-slate-700">✓ {{ $c['name'] }} · {{ ucfirst($c['platform']) }}</p>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">WhatsApp</p>
            <p class="mt-1 text-sm text-slate-600">Al que llevan los botones y la variable {{'{url_whatsapp}'}}.</p>

            @can('social_commerce.manage')
                <form method="PUT" action="{{ route('panel.social-commerce.settings.update') }}" class="mt-3 space-y-3">
                    @csrf @method('PUT')
                    <div>
                        <label class="bmos-field-label">Número de WhatsApp</label>
                        <input type="text" name="whatsapp_number" class="bmos-input" placeholder="18095551234"
                               value="{{ old('whatsapp_number', $ajustes->whatsapp_number) }}">
                        <p class="mt-1 text-xs text-slate-400">Con el código de país, sin espacios ni el signo +.</p>
                    </div>

                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50/50 p-3 text-sm text-slate-700">
                        <input type="checkbox" name="is_active" value="1" class="mt-0.5 rounded border-slate-300"
                               @checked($ajustes->is_active)>
                        <span>
                            <b>Recibir avisos de Instagram</b>
                            <span class="block text-xs text-slate-500">Necesario para que las conversaciones lleguen al CRM y se registre qué plantilla se usó.</span>
                        </span>
                    </label>

                    <div class="flex justify-end">
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar</button>
                    </div>
                </form>
            @endcan
        </div>
    </div>
</x-layouts.admin>
