{{-- El cliente se arrepintió de su baja. Cuerpo dentro del armazón compartido. --}}
<x-mail.layout preheader="Tu suscripción de {{ $companyName }} sigue activa y se renovará el {{ $renewsAt->format('d/m/Y') }}."
               :supportWhatsapp="$supportWhatsapp" :supportEmail="$supportEmail">

    {{-- Saludo --}}
    <tr>
        <td class="px" style="padding:14px 36px 0 36px;">
            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                ¡Qué bueno que te quedas, {{ $firstName }}!
            </h1>
            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                Reactivaste la suscripción de <strong style="color:#1e2230;">{{ $companyName }}</strong>.
                Todo sigue como antes: no perdiste nada. ✅
            </p>
        </td>
    </tr>

    {{-- Detalle del plan --}}
    <tr>
        <td class="px" style="padding:20px 36px 0 36px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background-color:#eef2ff; border-radius:12px;">
                <tr>
                    <td style="padding:16px 18px;">
                        <p style="margin:0 0 3px 0; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; color:#6366f1; font-weight:bold;">
                            Plan {{ $planName }}
                        </p>
                        <p style="margin:0 0 6px 0; font-size:16px; color:#1e2230; font-weight:bold;">
                            RD$ {{ number_format((float) $planPrice, 2) }} · {{ $billingCycleLabel }}
                        </p>
                        <p style="margin:0; font-size:13px; color:#4b5162;">
                            Próxima renovación: <strong style="color:#1e2230;">{{ $renewsAt->format('d/m/Y') }}</strong>
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
                    <a href="{{ $accountUrl }}" target="_blank"
                       style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:10px;">
                        Ir a mi cuenta &rarr;
                    </a>
                </td>
            </tr></table>
        </td>
    </tr>
</x-mail.layout>
