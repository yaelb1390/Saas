{{-- Código para crear una contraseña nueva. Cuerpo dentro del armazón compartido. --}}
<x-mail.layout preheader="Tu código para crear una contraseña nueva. Caduca en {{ $expiresInMinutes }} minutos."
               :supportWhatsapp="$supportWhatsapp" :supportEmail="$supportEmail">

    {{-- Saludo --}}
    <tr>
        <td class="px" style="padding:14px 36px 0 36px;">
            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                Hola, {{ $ownerName }}
            </h1>
            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                Recibimos una solicitud para cambiar la contraseña de tu cuenta.
                Escribe este código en la pantalla donde lo pidieron:
            </p>
        </td>
    </tr>

    {{-- El código, grande y espaciado: nada que pulsar, solo teclear. --}}
    <tr>
        <td class="px" align="center" style="padding:26px 36px 6px 36px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                <td align="center" bgcolor="#f6f7fb" style="border-radius:12px; padding:18px 32px;">
                    <span style="font-family:'Courier New',monospace; font-size:34px; font-weight:bold; letter-spacing:10px; color:#1e2230;">
                        {{ $code }}
                    </span>
                </td>
            </tr></table>
        </td>
    </tr>

    {{-- Caducidad. --}}
    <tr>
        <td class="px" align="center" style="padding:10px 36px 0 36px;">
            <p style="margin:0; font-size:13px; color:#6b7280;">
                Este código caduca en {{ $expiresInMinutes }} minutos y solo se puede usar una vez.
            </p>
        </td>
    </tr>

    {{-- Aviso de seguridad: si no fue él, no tiene que hacer NADA. --}}
    <tr>
        <td class="px" style="padding:24px 36px 0 36px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background-color:#f6f7fb; border-radius:12px;">
                <tr>
                    <td style="padding:14px 18px;">
                        <p style="margin:0; font-size:13px; line-height:1.6; color:#4b5162;">
                            <strong style="color:#1e2230;">¿No fuiste tú?</strong>
                            No tienes que hacer nada: tu contraseña sigue siendo la misma mientras no
                            uses este código.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</x-mail.layout>
