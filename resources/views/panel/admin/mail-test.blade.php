{{--
    Correos de prueba de la plataforma.

    Comprobar que un correo LLEGA no se puede hacer desde el código: Brevo lo da por «Entregado» cuando el
    servidor del destinatario lo acepta, y aun así Hotmail o Gmail pueden mandarlo a spam o descartarlo sin
    avisar. La única prueba es mandarlo a un buzón de verdad y mirar. Esto lo hace sin provocar una baja
    real en Polar.
--}}
<x-layouts.admin title="Correos de prueba" heading="Correos de prueba"
                 subheading="Manda a mano los correos que reciben tus clientes para comprobar que llegan">
    <div class="mx-auto max-w-3xl space-y-5">
        {{-- Con qué se envía. Si el envío no sale de la aplicación, ninguna prueba llegaría jamás y hay
             que decirlo antes de que alguien pierda un rato buscando en la bandeja. --}}
        @if (in_array($mailer, ['log', 'array'], true))
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="font-semibold text-amber-900">Aquí los correos no salen de la aplicación</p>
                <p class="mt-1 text-sm text-amber-800">
                    El envío está en modo «{{ $mailer }}»: los mensajes se escriben en un registro y no llegan
                    a nadie. Para probar de verdad hace falta el servidor SMTP de producción.
                </p>
            </div>
        @endif

        <div class="bmos-card bmos-card-pad">
            <p class="mb-3 text-sm font-semibold text-slate-600">Cómo se envía</p>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-slate-400">Envío</dt>
                    <dd class="font-medium text-slate-700">
                        {{ $mailer }}@if ($servidor) · <span class="font-mono text-xs">{{ $servidor }}</span>@endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Remitente</dt>
                    <dd class="font-medium text-slate-700">{{ $nombreRemitente }} &lt;{{ $remitente }}&gt;</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Responder a</dt>
                    <dd class="font-medium text-slate-700">{{ $soporte !== '' ? $soporte : '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="bmos-card bmos-card-pad">
            <form method="POST" action="{{ route('platform.mail-test.send') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="bmos-field-label" for="plantilla">Qué correo</label>
                    <select id="plantilla" name="plantilla" class="bmos-input" required>
                        @foreach ($plantillas as $clave => $datos)
                            <option value="{{ $clave }}" @selected(old('plantilla', 'baja') === $clave)>
                                {{ $datos[0] }} — {{ $datos[1] }}
                            </option>
                        @endforeach
                    </select>
                    @error('plantilla') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="bmos-field-label" for="destino">Enviar a</label>
                    <input id="destino" type="email" name="destino" class="bmos-input" required
                           value="{{ old('destino') }}" placeholder="tucorreo@gmail.com" autocomplete="off">
                    <p class="mt-1 text-xs text-slate-400">
                        Prueba con varios buzones —Gmail, Hotmail, el de un conocido—: si llega a unos y no a
                        otros, el problema es de ese proveedor y no del correo.
                    </p>
                    @error('destino') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-start gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="sin_reply_to" value="1" class="mt-0.5 rounded border-slate-300"
                           @checked(old('sin_reply_to'))>
                    <span>
                        Sin la cabecera «Responder a»
                        <span class="block text-xs text-slate-400">
                            Solo para la baja y la reactivación. Sirve para saber si esa cabecera es lo que hace
                            que un buzón los rechace: prueba el mismo correo con y sin ella.
                        </span>
                    </span>
                </label>

                <button type="submit" class="bmos-btn bmos-btn-primary">
                    <x-icono name="enviar" class="h-4 w-4" stroke-width="1.8" />
                    Enviar correo de prueba
                </button>
            </form>
        </div>

        <div class="bmos-card bmos-card-pad">
            <p class="mb-2 text-sm font-semibold text-slate-600">Cómo leer el resultado</p>
            <ul class="list-disc space-y-1.5 pl-5 text-sm text-slate-500">
                <li>
                    <b>«Correo de prueba enviado»</b> solo dice que el servidor de correo lo aceptó. No dice
                    que esté en la bandeja de entrada.
                </li>
                <li>
                    Confirma en <b>Brevo → Transactional → Logs</b>: «Entregado» es que el buzón del destinatario
                    lo aceptó; «Abierto» es que alguien lo abrió.
                </li>
                <li>
                    Si Brevo dice «Entregado» y no lo ves, mira <b>Correo no deseado</b> y la pestaña
                    <b>Otros</b>. Si tampoco está, el proveedor lo descartó: prueba sin «Responder a» y con
                    otro buzón.
                </li>
                <li>
                    Si sale un error, se muestra aquí tal cual: casi siempre dice si es la clave, el remitente
                    sin verificar o el servidor caído.
                </li>
            </ul>
        </div>
    </div>
</x-layouts.admin>
