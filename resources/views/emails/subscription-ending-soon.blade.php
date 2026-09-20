{{-- Recordatorio a quien YA pidió la baja: su acceso termina y todavía puede reactivarla. Cuerpo dentro del
     armazón compartido. En ámbar: es un recordatorio con fecha, lo único que pide atención. --}}
<x-mail.layout preheader="Tu acceso termina el {{ $accessUntil->format('d/m/Y') }}. Todavía puedes reactivar tu suscripción."
               :supportWhatsapp="$supportWhatsapp" :supportEmail="$supportEmail">

    {{-- Saludo --}}
    <tr>
        <td class="px" style="padding:14px 36px 0 36px;">
            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                Hola, {{ $firstName }}
            </h1>
            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                Te recordamos que la suscripción de <strong style="color:#1e2230;">{{ $companyName }}</strong>
                termina pronto, porque pediste la baja. Hasta esa fecha sigues con acceso completo.
            </p>
        </td>
    </tr>

    {{-- Cuándo termina --}}
    <tr>
        <td class="px" style="padding:20px 36px 0 36px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background-color:#fffbeb; border-radius:12px;">
                <tr>
                    <td style="padding:16px 18px;">
                        <p style="margin:0 0 3px 0; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; color:#b45309; font-weight:bold;">
                            Tu acceso termina
                        </p>
                        <p style="margin:0 0 6px 0; font-size:16px; color:#1e2230; font-weight:bold;">
                            {{ $accessUntil->format('d/m/Y') }}
                            @if ($daysLeft > 1)
                                <span style="font-weight:normal; color:#6b7280;"> · en {{ $daysLeft }} días</span>
                            @elseif ($daysLeft === 1)
                                <span style="font-weight:normal; color:#6b7280;"> · mañana</span>
                            @elseif ($daysLeft === 0)
                                <span style="font-weight:normal; color:#6b7280;"> · hoy</span>
                            @endif
                        </p>
                        <p style="margin:0; font-size:13px; line-height:1.5; color:#92400e;">
                            Después de esa fecha no podrás entrar a los módulos del plan {{ $planName }}.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- ¿Cambió de idea? --}}
    <tr>
        <td class="px" style="padding:18px 36px 0 36px;">
            <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5162;">
                <strong style="color:#1e2230;">¿Cambiaste de idea?</strong>
                Reactívala con un clic desde tu panel y todo seguirá como antes.
            </p>
        </td>
    </tr>

    {{-- Botón --}}
    <tr>
        <td class="px" align="center" style="padding:24px 36px 6px 36px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                <td align="center" bgcolor="#4f46e5" style="border-radius:10px;">
                    <a href="{{ $accountUrl }}" target="_blank"
                       style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:10px;">
                        Reactivar mi suscripción &rarr;
                    </a>
                </td>
            </tr></table>
        </td>
    </tr>

    {{-- Sus datos --}}
    <tr>
        <td class="px" style="padding:20px 36px 0 36px;">
            <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5162;">
                <strong style="color:#1e2230;">Tus datos están a salvo.</strong>
                Si prefieres irte, no borramos nada de tu empresa: cuando quieras volver, contratas de nuevo
                y sigues donde lo dejaste.
            </p>
        </td>
    </tr>
</x-mail.layout>
