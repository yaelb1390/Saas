{{-- Aviso de que la suscripción se renovará SOLA. Cuerpo dentro del armazón compartido.
     No hay nada que renovar: se dice cuándo se cobra y cuánto, y cómo cancelar o cambiar la tarjeta si no
     quiere que ocurra. Lo contrario —«renueva a tiempo»— llevaba a pagar algo que ya se paga solo. --}}
<x-mail.layout preheader="Tu suscripción se renovará automáticamente el {{ $renewsAt->format('d/m/Y') }}. No tienes que hacer nada."
               :supportWhatsapp="$supportWhatsapp" :supportEmail="$supportEmail">

    {{-- Saludo --}}
    <tr>
        <td class="px" style="padding:14px 36px 0 36px;">
            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                Hola, {{ $firstName }}
            </h1>
            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                Te avisamos con tiempo: la suscripción de
                <strong style="color:#1e2230;">{{ $companyName }}</strong> se renovará automáticamente.
                <strong style="color:#1e2230;">No tienes que hacer nada.</strong>
            </p>
        </td>
    </tr>

    {{-- Cuándo y cuánto --}}
    <tr>
        <td class="px" style="padding:20px 36px 0 36px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background-color:#eef2ff; border-radius:12px;">
                <tr>
                    <td style="padding:16px 18px;">
                        <p style="margin:0 0 3px 0; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; color:#6366f1; font-weight:bold;">
                            Renovación automática
                        </p>
                        <p style="margin:0 0 6px 0; font-size:16px; color:#1e2230; font-weight:bold;">
                            {{ $renewsAt->format('d/m/Y') }}
                            @if ($daysLeft > 1)
                                <span style="font-weight:normal; color:#6b7280;"> · en {{ $daysLeft }} días</span>
                            @elseif ($daysLeft === 1)
                                <span style="font-weight:normal; color:#6b7280;"> · mañana</span>
                            @elseif ($daysLeft === 0)
                                <span style="font-weight:normal; color:#6b7280;"> · hoy</span>
                            @endif
                        </p>
                        <p style="margin:0; font-size:13px; line-height:1.5; color:#4b5162;">
                            Se cobrarán RD$ {{ number_format((float) $planPrice, 2) }} ({{ mb_strtolower($billingCycleLabel) }})
                            a la tarjeta con la que pagaste. Plan {{ $planName }}.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Si no quiere que ocurra --}}
    <tr>
        <td class="px" style="padding:18px 36px 0 36px;">
            <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5162;">
                ¿No quieres renovar? Cancela la suscripción desde tu panel antes de esa fecha y conservas el
                acceso hasta el fin del período. ¿Cambió tu tarjeta o vence pronto?
                <a href="{{ $updateCardUrl }}" style="color:#4f46e5; font-weight:bold; text-decoration:none;">Actualízala aquí</a>.
            </p>
        </td>
    </tr>

    {{-- Botón --}}
    <tr>
        <td class="px" align="center" style="padding:26px 36px 6px 36px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                <td align="center" bgcolor="#4f46e5" style="border-radius:10px;">
                    <a href="{{ $accountUrl }}" target="_blank"
                       style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:10px;">
                        Administrar mi suscripción &rarr;
                    </a>
                </td>
            </tr></table>
        </td>
    </tr>
</x-mail.layout>
