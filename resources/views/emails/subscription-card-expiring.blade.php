{{-- La tarjeta con la que se paga vence. Cuerpo dentro del armazón compartido.
     Sustituye al aviso de renovación (mismo momento), por eso repite cuándo y cuánto se cobra.
     En ámbar si el cobro va a fallar; en índigo si la tarjeta aún sirve pero caduca enseguida. --}}
<x-mail.layout :preheader="$failsAtRenewal ? 'Tu tarjeta vence antes de la próxima renovación. Actualízala para no perder el acceso.' : 'Tu tarjeta vence pronto. Conviene actualizarla antes del próximo cobro.'"
               :supportWhatsapp="$supportWhatsapp" :supportEmail="$supportEmail">

    {{-- Saludo --}}
    <tr>
        <td class="px" style="padding:14px 36px 0 36px;">
            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                Hola, {{ $firstName }}
            </h1>
            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                @if ($failsAtRenewal)
                    La tarjeta con la que se paga la suscripción de
                    <strong style="color:#1e2230;">{{ $companyName }}</strong> vence en {{ $cardExpiry }},
                    <strong style="color:#1e2230;">antes de la próxima renovación</strong>.
                    Si no la actualizas, el cobro fallará y perderás el acceso.
                @else
                    La tarjeta con la que se paga la suscripción de
                    <strong style="color:#1e2230;">{{ $companyName }}</strong> vence en {{ $cardExpiry }}.
                    Todavía sirve para la próxima renovación, pero conviene cambiarla ya para que el cobro
                    siguiente no falle.
                @endif
            </p>
        </td>
    </tr>

    {{-- Qué tarjeta y qué se cobra --}}
    <tr>
        <td class="px" style="padding:20px 36px 0 36px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background-color:{{ $failsAtRenewal ? '#fffbeb' : '#eef2ff' }}; border-radius:12px;">
                <tr>
                    <td style="padding:16px 18px;">
                        <p style="margin:0 0 3px 0; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; color:{{ $failsAtRenewal ? '#b45309' : '#6366f1' }}; font-weight:bold;">
                            Tarjeta por vencer
                        </p>
                        <p style="margin:0 0 6px 0; font-size:16px; color:#1e2230; font-weight:bold;">
                            {{ $cardName }}
                            <span style="font-weight:normal; color:#6b7280;"> · vence {{ $cardExpiry }}</span>
                        </p>
                        <p style="margin:0; font-size:13px; line-height:1.5; color:{{ $failsAtRenewal ? '#92400e' : '#4b5162' }};">
                            Próxima renovación: {{ $renewsAt->format('d/m/Y') }} · RD$ {{ number_format((float) $planPrice, 2) }}
                            ({{ mb_strtolower($billingCycleLabel) }}). Plan {{ $planName }}.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Botón --}}
    <tr>
        <td class="px" align="center" style="padding:26px 36px 6px 36px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                <td align="center" bgcolor="#4f46e5" style="border-radius:10px;">
                    <a href="{{ $updateCardUrl }}" target="_blank"
                       style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:10px;">
                        Actualizar mi tarjeta &rarr;
                    </a>
                </td>
            </tr></table>
        </td>
    </tr>
    <tr>
        <td class="px" align="center" style="padding:8px 36px 0 36px;">
            <p style="margin:0; font-size:12px; line-height:1.5; color:#6b7280;">
                Te llevamos a la página segura de pagos, donde puedes cambiar la tarjeta.
            </p>
        </td>
    </tr>

    {{-- Si prefiere no renovar --}}
    <tr>
        <td class="px" style="padding:20px 36px 0 36px;">
            <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5162;">
                ¿No quieres seguir? Puedes cancelar la suscripción desde
                <a href="{{ $accountUrl }}" style="color:#4f46e5; font-weight:bold; text-decoration:none;">tu panel</a>
                antes de esa fecha y conservas el acceso hasta el fin del período.
            </p>
        </td>
    </tr>
</x-mail.layout>
