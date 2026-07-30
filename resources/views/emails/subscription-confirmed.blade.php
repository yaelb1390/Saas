{{-- Confirmación de suscripción/pago. Cuerpo dentro del armazón compartido. --}}
<x-mail.layout preheader="Tu suscripción al plan {{ $planName }} está activa."
               :supportWhatsapp="$supportWhatsapp" :supportEmail="$supportEmail">

    {{-- Saludo --}}
    <tr>
        <td class="px" style="padding:14px 36px 0 36px;">
            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                ¡Gracias, {{ $ownerName }}!
            </h1>
            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                La suscripción de <strong style="color:#1e2230;">{{ $companyName }}</strong> quedó confirmada.
                Ya tienes acceso completo a tu plan. ✅
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

    {{-- Módulos incluidos --}}
    @if (! empty($moduleLabels))
        <tr>
            <td class="px" style="padding:20px 36px 0 36px;">
                <p style="margin:0 0 8px 0; font-size:13px; color:#6b7280; font-weight:bold;">
                    Módulos incluidos
                </p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td>
                    @foreach ($moduleLabels as $label)
                        <span style="display:inline-block; margin:0 6px 6px 0; padding:5px 11px; background-color:#f1f5f9; border:1px solid #e2e8f0; border-radius:999px; font-size:12px; color:#334155;">{{ $label }}</span>
                    @endforeach
                </td></tr></table>
            </td>
        </tr>
    @endif

    {{-- Botón --}}
    <tr>
        <td class="px" align="center" style="padding:26px 36px 6px 36px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                <td align="center" bgcolor="#4f46e5" style="border-radius:10px;">
                    <a href="{{ $loginUrl }}" target="_blank"
                       style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:10px;">
                        Ir a mi panel &rarr;
                    </a>
                </td>
            </tr></table>
        </td>
    </tr>
</x-mail.layout>
