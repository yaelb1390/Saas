{{-- La suscripción terminó y se retiró el acceso. Cuerpo dentro del armazón compartido.
     Dice cosas distintas según la pidiera el cliente o no, pero en los dos casos lo primero que necesita
     saber es que sus datos no se borran. --}}
<x-mail.layout preheader="Tu suscripción terminó. Tus datos siguen guardados."
               :supportWhatsapp="$supportWhatsapp" :supportEmail="$supportEmail">

    {{-- Saludo --}}
    <tr>
        <td class="px" style="padding:14px 36px 0 36px;">
            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                Hola, {{ $firstName }}
            </h1>
            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                @if ($requestedByCustomer)
                    La suscripción de <strong style="color:#1e2230;">{{ $companyName }}</strong> terminó hoy, como
                    pediste. Gracias por haber usado BM Business OS.
                @else
                    La suscripción de <strong style="color:#1e2230;">{{ $companyName }}</strong> terminó y ya no tienes
                    acceso a los módulos. Suele pasar cuando no se puede cobrar la renovación.
                @endif
            </p>
        </td>
    </tr>

    {{-- Qué terminó --}}
    <tr>
        <td class="px" style="padding:20px 36px 0 36px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background-color:#f1f5f9; border-radius:12px;">
                <tr>
                    <td style="padding:16px 18px;">
                        <p style="margin:0 0 3px 0; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; color:#64748b; font-weight:bold;">
                            Suscripción terminada
                        </p>
                        <p style="margin:0 0 6px 0; font-size:16px; color:#1e2230; font-weight:bold;">
                            Plan {{ $planName }}
                        </p>
                        <p style="margin:0; font-size:13px; line-height:1.5; color:#4b5162;">
                            Tu acceso a los módulos se retiró.
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
                No borramos nada de tu empresa: cuando quieras volver, contrata de nuevo y sigues donde lo dejaste.
            </p>
        </td>
    </tr>

    {{-- Botón --}}
    <tr>
        <td class="px" align="center" style="padding:26px 36px 6px 36px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                <td align="center" bgcolor="#4f46e5" style="border-radius:10px;">
                    <a href="{{ $resubscribeUrl }}" target="_blank"
                       style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:10px;">
                        Volver a contratar &rarr;
                    </a>
                </td>
            </tr></table>
        </td>
    </tr>

    {{-- Su opinión. Responder a este correo llega a soporte: ver `replyTo` en la clase. --}}
    <tr>
        <td class="px" style="padding:22px 36px 0 36px;">
            <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5162;">
                ¿Nos cuentas cómo podríamos mejorar? Responde a este correo o escríbenos por WhatsApp.
                Tu opinión nos ayuda.
            </p>
        </td>
    </tr>
</x-mail.layout>
