{{-- Aviso de vencimiento de suscripción. Cuerpo dentro del armazón compartido. --}}
<x-mail.layout preheader="Tu suscripción está por vencer. Renueva para no perder el acceso."
               :supportWhatsapp="$supportWhatsapp" :supportEmail="$supportEmail">

    {{-- Saludo --}}
    <tr>
        <td class="px" style="padding:14px 36px 0 36px;">
            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                Hola, {{ $ownerName }}
            </h1>
            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                Tu suscripción de <strong style="color:#1e2230;">{{ $companyName }}</strong> está por vencer.
                Renueva a tiempo para que tu equipo no pierda el acceso.
            </p>
        </td>
    </tr>

    {{-- Aviso de vencimiento --}}
    <tr>
        <td class="px" style="padding:20px 36px 0 36px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background-color:#fffbeb; border-radius:12px;">
                <tr>
                    <td style="padding:16px 18px; border-left:4px solid #f59e0b;">
                        <p style="margin:0 0 3px 0; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; color:#b45309; font-weight:bold;">
                            Plan {{ $planName }}
                        </p>
                        <p style="margin:0; font-size:16px; color:#92400e; font-weight:bold;">
                            @if ($daysLeft <= 0)
                                Vence hoy ({{ $renewsAt->format('d/m/Y') }})
                            @else
                                Vence el {{ $renewsAt->format('d/m/Y') }} · faltan {{ $daysLeft }} {{ $daysLeft === 1 ? 'día' : 'días' }}
                            @endif
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Cómo renovar --}}
    <tr>
        <td class="px" style="padding:18px 36px 0 36px;">
            <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5162;">
                Para renovar, escríbenos por WhatsApp o correo (abajo) y te ayudamos a mantener tu cuenta activa.
            </p>
        </td>
    </tr>

    {{-- Botón --}}
    <tr>
        <td class="px" align="center" style="padding:24px 36px 6px 36px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                <td align="center" bgcolor="#4f46e5" style="border-radius:10px;">
                    <a href="{{ $loginUrl }}" target="_blank"
                       style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:10px;">
                        Ver mi suscripción &rarr;
                    </a>
                </td>
            </tr></table>
        </td>
    </tr>
</x-mail.layout>
