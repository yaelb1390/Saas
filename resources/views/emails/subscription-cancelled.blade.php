{{-- Baja de suscripción: el cliente pidió cancelar. Cuerpo dentro del armazón compartido.
     Cancelar NO corta el acceso: el correo lo dice arriba y con la fecha, porque es lo primero que
     se pregunta quien acaba de cancelar. --}}
<x-mail.layout preheader="Sigues con acceso completo hasta el {{ $accessUntil->format('d/m/Y') }}."
               :supportWhatsapp="$supportWhatsapp" :supportEmail="$supportEmail">

    {{-- Saludo --}}
    <tr>
        <td class="px" style="padding:14px 36px 0 36px;">
            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                Hola, {{ $firstName }}
            </h1>
            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                Recibimos la cancelación de la suscripción de <strong style="color:#1e2230;">{{ $companyName }}</strong>.
                Lamentamos que te vayas, y queremos que sepas que
                <strong style="color:#1e2230;">no pierdes nada de lo que ya pagaste</strong>.
            </p>
        </td>
    </tr>

    {{-- Hasta cuándo --}}
    <tr>
        <td class="px" style="padding:20px 36px 0 36px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background-color:#eef2ff; border-radius:12px;">
                <tr>
                    <td style="padding:16px 18px;">
                        <p style="margin:0 0 3px 0; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; color:#6366f1; font-weight:bold;">
                            Tu acceso sigue activo
                        </p>
                        <p style="margin:0 0 6px 0; font-size:16px; color:#1e2230; font-weight:bold;">
                            Hasta el {{ $accessUntil->format('d/m/Y') }}
                            @if ($daysLeft > 1)
                                <span style="font-weight:normal; color:#6b7280;"> · te quedan {{ $daysLeft }} días</span>
                            @elseif ($daysLeft === 1)
                                <span style="font-weight:normal; color:#6b7280;"> · te queda 1 día</span>
                            @endif
                        </p>
                        <p style="margin:0; font-size:13px; line-height:1.5; color:#4b5162;">
                            Hasta esa fecha sigues usando el plan {{ $planName }} con todos sus módulos.
                            Después no se cobrará ni se renovará más.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Sus datos --}}
    <tr>
        <td class="px" style="padding:18px 36px 0 36px;">
            <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5162;">
                <strong style="color:#1e2230;">Tus datos están a salvo.</strong>
                No borramos nada de tu empresa: si más adelante quieres volver, solo tienes que contratar
                de nuevo y sigues donde lo dejaste.
            </p>
        </td>
    </tr>

    {{-- ¿Cambió de idea? --}}
    <tr>
        <td class="px" align="center" style="padding:26px 36px 0 36px;">
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
    <tr>
        <td class="px" align="center" style="padding:8px 36px 0 36px;">
            <p style="margin:0; font-size:12px; line-height:1.5; color:#6b7280;">
                Puedes reactivarla cuando quieras hasta el {{ $accessUntil->format('d/m/Y') }}, y seguirá como antes.
            </p>
        </td>
    </tr>

    {{-- Su opinión. Responder a este correo llega a soporte: ver `replyTo` en la clase. --}}
    <tr>
        <td class="px" style="padding:22px 36px 0 36px;">
            <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5162;">
                ¿Nos cuentas por qué cancelas? Responde a este correo o escríbenos por WhatsApp.
                Tu opinión nos ayuda a mejorar.
            </p>
        </td>
    </tr>
</x-mail.layout>
