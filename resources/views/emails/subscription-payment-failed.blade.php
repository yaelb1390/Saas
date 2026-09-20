{{-- No se pudo cobrar la renovación. Cuerpo dentro del armazón compartido.
     El botón lleva a una ruta de la app y no al portal de Polar: el enlace de una sesión del portal caduca
     en una hora y este correo se puede abrir días después. --}}
<x-mail.layout preheader="No pudimos cobrar tu suscripción. Actualiza tu tarjeta para no perder el acceso."
               :supportWhatsapp="$supportWhatsapp" :supportEmail="$supportEmail">

    {{-- Saludo --}}
    <tr>
        <td class="px" style="padding:14px 36px 0 36px;">
            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                Hola, {{ $firstName }}
            </h1>
            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                No pudimos cobrar la renovación de la suscripción de
                <strong style="color:#1e2230;">{{ $companyName }}</strong>.
                Suele pasar porque la tarjeta venció, no tiene fondos o el banco bloqueó el cobro.
            </p>
        </td>
    </tr>

    {{-- Qué está pendiente. En ámbar: es lo único de este correo que pide atención. --}}
    <tr>
        <td class="px" style="padding:20px 36px 0 36px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background-color:#fffbeb; border-radius:12px;">
                <tr>
                    <td style="padding:16px 18px;">
                        <p style="margin:0 0 3px 0; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; color:#b45309; font-weight:bold;">
                            Cobro pendiente
                        </p>
                        <p style="margin:0 0 6px 0; font-size:16px; color:#1e2230; font-weight:bold;">
                            RD$ {{ number_format((float) $planPrice, 2) }} · {{ $billingCycleLabel }}
                        </p>
                        <p style="margin:0; font-size:13px; line-height:1.5; color:#92400e;">
                            Plan {{ $planName }}. Actualiza tu método de pago para no perder el acceso.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Qué pasa si no se resuelve --}}
    <tr>
        <td class="px" style="padding:18px 36px 0 36px;">
            <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5162;">
                Cuando la actualices, se volverá a intentar el cobro. Si el problema no se resuelve, la suscripción
                se cancelará y perderás el acceso a tu cuenta.
                <strong style="color:#1e2230;">Tus datos no se borran</strong>: puedes volver cuando quieras.
            </p>
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
</x-mail.layout>
