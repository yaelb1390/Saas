@use('App\Modules\Social\Enums\SocialPlatform')
@use('App\Modules\Social\Enums\KeywordMatch')

{{--
    Respuestas automáticas: alguien comenta una palabra y el negocio le contesta solo.

    Esto habla con clientes reales SIN NADIE DELANTE. Una palabra clave mal elegida contesta a quien no
    debía y un enlace equivocado se manda cientos de veces antes de que alguien lo note. De ahí que la
    pantalla insista en tres cosas: qué palabra dispara, a quién le va a llegar, y qué lleva hecho.
--}}
<x-layouts.admin title="Respuestas automáticas" heading="Respuestas automáticas"
                 subheading="Cuando alguien comenta una palabra, el sistema le contesta y le escribe">
    <div class="mx-auto max-w-4xl">
        <a href="{{ route('panel.social') }}" class="mb-4 inline-block text-sm text-indigo-600 hover:underline">
            ← Volver a Redes sociales
        </a>

        @if ($aviso)
            <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-medium text-amber-900">{{ $aviso }}</p>
            </div>
        @endif

        @if (! $configurado)
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Primero conecta tus redes</p>
                <p class="mt-2 text-sm text-slate-600">
                    Las respuestas automáticas necesitan una cuenta conectada.
                    <a href="{{ route('panel.social') }}" class="text-indigo-600 hover:underline">Ir a Redes sociales</a>.
                </p>
            </div>
        @elseif ($cuentas === [])
            {{-- Solo Instagram y Facebook lo admiten: es limitación de las propias redes. Decirlo
                 evita que alguien busque el botón durante media hora. --}}
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">No hay ninguna cuenta que pueda hacer esto</p>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    Las respuestas automáticas solo funcionan en <b>Instagram</b> y <b>Facebook</b>: las
                    demás redes no lo permiten. Conecta una de esas dos en
                    <a href="{{ route('panel.social') }}" class="text-indigo-600 hover:underline">Redes sociales</a>
                    y vuelve aquí.
                </p>
            </div>
        @else
            {{-- El resumen de todas.

                 Sale de los contadores que YA vinieron para pintar la lista: no cuesta ni una
                 llamada más. Un panel aparte necesitaría una por automatización, con su plazo cada
                 una, y acabaría siendo más lento que aquello sobre lo que informa. --}}
            @if ($automatizaciones !== [])
                @php
                    // «Sin fallos» solo significa algo si algo llegó a ocurrir. Sin un disparo en
                    // todo el negocio, la ficha sale apagada en vez de felicitar por nada, igual que
                    // hace una tarjeta recién creada.
                    $huboActividad = $resumen['triggered'] > 0;
                    $sinFallos = $huboActividad && $resumen['failed'] === 0;
                @endphp
                <div class="mb-5">
                    @include('partials.cifras', ['fichas' => [
                        ['tone-amber', 'encendida', $resumen['encendidas'], $resumen['encendidas'] === 1 ? 'encendida' : 'encendidas'],
                        ['tone-indigo', 'mensaje', $resumen['sent'], 'mensajes enviados'],
                        ['tone-violet', 'personas', $resumen['people'], 'personas alcanzadas'],
                        [$resumen['failed'] > 0 ? 'tone-rose' : ($sinFallos ? 'tone-emerald' : 'es-neutra'),
                         $resumen['failed'] > 0 ? 'fallo' : 'bien',
                         $resumen['failed'],
                         $sinFallos ? 'sin fallos' : 'fallaron'],
                    ]])
                </div>
                @if (count($automatizaciones) > 1)
                    {{-- «Personas» es el único que no se puede sumar de verdad: quien comentó en dos
                         automatizaciones cuenta dos veces. Se dice en vez de fingir precisión. --}}
                    <p class="-mt-3 mb-5 text-xs text-slate-400">
                        Quien haya escrito en varias respuestas automáticas cuenta una vez en cada una.
                    </p>
                @endif
            @endif

            {{-- Las que ya existen --}}
            @forelse ($automatizaciones as $a)
                @php $red = SocialPlatform::tryFrom($a['platform']); @endphp
                {{-- Lleva el color de su red en la franja izquierda, igual que la tarjeta de la
                     cuenta: con varias automatizaciones en la lista, es lo que dice de un vistazo
                     cuál contesta en Instagram y cuál en Facebook. Apagada se pone gris, porque
                     entonces lo que importa no es de qué red es, sino que no está contestando. --}}
                <div class="bmos-card bmos-card-pad bmos-marca mb-4 {{ $a['isActive'] ? '' : 'is-apagada' }}"
                     style="--tono: {{ $red?->color() ?? '#94a3b8' }}">
                    {{-- REJILLA, no flex-wrap.

                         Con muchas palabras clave, el flex envolvía y tiraba «Encendida / Apagar»
                         debajo del cuerpo: la retahíla de palabras aportaba un ancho mínimo enorme
                         y el `min-w-0` no bastaba. Las pistas de una rejilla no envuelven, y el
                         `minmax(0,1fr)` deja que la columna de texto encoja por debajo de su ancho
                         mínimo. Apilar en móvil pasa a ser una decisión y no un accidente. --}}
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-800">
                                {{ $a['name'] }}
                            </p>
                            {{-- La red va escrita, no solo en el color de la franja: un color no se
                                 lee en voz alta ni lo distingue quien no ve bien el rojo. --}}
                            <p class="mt-1 text-sm text-slate-600">
                                En <b>{{ $red?->label() ?? $a['platform'] }}</b>, contesta a:
                            </p>

                            {{-- Las palabras, en fichas. Tope de seis y un «+N»: con quince, la
                                 tarjeta dejaba de poder leerse de un vistazo. --}}
                            <div class="mt-1.5 flex flex-wrap items-center gap-1">
                                @forelse (array_slice($a['keywords'], 0, 6) as $palabra)
                                    <span class="bmos-clave">{{ $palabra }}</span>
                                @empty
                                    <span class="bmos-clave">cualquier comentario</span>
                                @endforelse
                                @if (count($a['keywords']) > 6)
                                    <span class="bmos-clave-mas">+{{ count($a['keywords']) - 6 }}</span>
                                @endif
                            </div>
                            <p class="mt-1.5 text-xs text-slate-400">
                                {{ $a['postId'] ? 'En una publicación concreta' : 'En cualquier publicación' }}
                                @if ($a['alsoMatchInDms']) · y a quien escriba por privado @endif
                                @if ($a['followGate']) · solo a quien te siga @endif
                                {{-- La espera se dice en la lista: es la diferencia entre «no funciona»
                                     y «todavía no le toca», y sin verla se abre un ticket por nada. --}}
                                @if ($a['dmDelaySeconds'] > 0)
                                    · espera {{ $a['dmDelaySeconds'] < 60
                                        ? $a['dmDelaySeconds'].' segundos'
                                        : round($a['dmDelaySeconds'] / 60).' min' }}
                                @endif
                            </p>

                            {{-- Cuántos textos distintos rotan. Va a la vista y no escondido en la
                                 edición porque una automatización con un solo texto es la que se
                                 gana el bloqueo por spam, y eso hay que verlo sin abrir nada. --}}
                            @php $versiones = 1 + count($a['dmMessageVariations']); @endphp
                            <p class="mt-1.5 text-xs {{ $versiones > 1 ? 'text-emerald-600' : 'text-amber-600' }}">
                                @if ($versiones > 1)
                                    Rotan {{ $versiones }} mensajes distintos
                                @else
                                    ⚠ Un solo mensaje, siempre igual — añade versiones para que no la marquen como spam
                                @endif
                            </p>
                        </div>

                        <div class="flex items-start gap-2 sm:justify-self-end">
                            <span class="bmos-badge {{ $a['isActive'] ? 'badge-green' : 'badge-gray' }}">
                                {{ $a['isActive'] ? 'Encendida' : 'Apagada' }}
                            </span>
                            {{-- Apagar es un clic: quien ve que está contestando mal no debería tener
                                 que abrir un formulario entero para pararla. --}}
                            <form method="POST" action="{{ route('panel.social.automations.toggle', $a['id']) }}">
                                @csrf
                                <input type="hidden" name="is_active" value="{{ $a['isActive'] ? '0' : '1' }}">
                                <button type="submit" class="bmos-btn bmos-btn-ghost text-xs">
                                    {{ $a['isActive'] ? 'Apagar' : 'Encender' }}
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- Lo que lleva hecho. Responde a la única pregunta que importa: ¿sirve de algo?

                         Las cifras son la PUERTA al reporte, no un enlace de texto al fondo: son
                         ellas las que levantan la pregunta de «¿y por qué falló ese?», así que el
                         clic tiene que estar donde nace la duda.

                         Va fuera de cualquier <form>: el bloque es hermano del <details>, no hijo. --}}
                    @if ($a['stats']['triggered'] > 0)
                        <a href="{{ route('panel.social.automations.reporte', $a['id'])
                                  .($a['stats']['failed'] > 0 ? '?estado=failed' : '') }}"
                           class="bmos-cifras-enlace group mt-3">
                            @include('partials.cifras', ['fichas' => [
                                ['tone-sky', 'comentario', $a['stats']['triggered'], 'veces'],
                                ['tone-indigo', 'mensaje', $a['stats']['sent'], 'mensajes'],
                                ['tone-violet', 'personas', $a['stats']['people'], 'personas'],
                                $a['stats']['failed'] > 0
                                    ? ['tone-rose', 'fallo', $a['stats']['failed'], 'fallaron']
                                    : ['tone-emerald', 'bien', 0, 'sin fallos'],
                            ]])
                            <p class="mt-1.5 text-right text-xs text-indigo-500 opacity-0 transition group-hover:opacity-100">
                                ver el reporte →
                            </p>
                        </a>
                    @else
                        {{-- Cuatro ceros no dicen nada, y un «sin fallos» verde sería mentira: no ha
                             acertado nada todavía porque no ha llegado a intentarlo. --}}
                        <p class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-500">
                            Todavía no se ha disparado. Si acabas de crearla, es lo normal: espera a que
                            alguien comente una de tus palabras.
                        </p>
                    @endif

                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer text-slate-500">Ver o cambiar el texto</summary>
                        <form method="POST" action="{{ route('panel.social.automations.update', $a['id']) }}" class="mt-3 space-y-3">
                            @csrf
                            @method('PUT')
                            @include('partials.social-automation-fields', ['a' => $a, 'cuentas' => $cuentas, 'coincidencias' => $coincidencias, 'publicaciones' => $publicaciones])
                            <div class="flex flex-wrap justify-between gap-2 pt-1">
                                <button type="submit" class="bmos-btn bmos-btn-primary">Guardar cambios</button>
                            </div>
                        </form>

                        <x-panel.confirm-action
                            :action="route('panel.social.automations.destroy', $a['id'])"
                            title="¿Borrar «{{ $a['name'] }}»?"
                            message="Dejará de contestar a quien comente esas palabras."
                            note="Si solo quieres pararla un rato, apágala en vez de borrarla: se conservan las estadísticas."
                            class="bmos-btn bmos-btn-ghost mt-2 text-xs text-rose-600">
                            Borrar
                        </x-panel.confirm-action>
                    </details>
                </div>
            @empty
                <div class="bmos-card bmos-card-pad mb-4">
                    <p class="text-slate-500">
                        Todavía no tienes ninguna. Crea la primera abajo: lo típico es
                        «quien comente <b>precio</b>, que reciba el catálogo por privado».
                    </p>
                </div>
            @endforelse

            {{-- Traer lo publicado desde el móvil.

                 Va FUERA de los formularios de automatización a propósito: un <form> dentro de otro
                 es HTML inválido y el navegador desmonta el interior en silencio, rompiendo el envío
                 del exterior. Ya pasó una vez en «Mi empresa». --}}
            <form method="POST" action="{{ route('panel.social.automations.sync') }}"
                  class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                @csrf
                <p class="text-sm text-slate-600">
                    ¿Quieres colgar la respuesta de <b>una foto concreta</b>?
                    <span class="block text-xs text-slate-400">
                        Traemos tus publicaciones de Instagram, también las que subiste desde el móvil.
                    </span>
                </p>
                <button type="submit" class="bmos-btn bmos-btn-ghost shrink-0 text-sm">
                    Buscar mis publicaciones
                </button>
            </form>

            {{-- Crear --}}
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Nueva respuesta automática</p>
                <form method="POST" action="{{ route('panel.social.automations.store') }}" class="mt-3 space-y-3">
                    @csrf
                    @include('partials.social-automation-fields', ['a' => null, 'cuentas' => $cuentas, 'coincidencias' => $coincidencias, 'publicaciones' => $publicaciones])
                    <button type="submit" class="bmos-btn bmos-btn-primary">Crear</button>
                </form>
            </div>
        @endif
    </div>
</x-layouts.admin>
