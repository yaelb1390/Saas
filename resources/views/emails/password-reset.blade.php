{{-- Enlace para crear una contraseña nueva. Cuerpo dentro del armazón compartido. --}}
<x-mail.layout preheader="Crea tu nueva contraseña. El enlace caduca en {{ $expiresInMinutes }} minutos."
               :supportWhatsapp="$supportWhatsapp" :supportEmail="$supportEmail">

    {{-- Saludo --}}
    <tr>
        <td class="px" style="padding:14px 36px 0 36px;">
            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                Hola, {{ $ownerName }}
            </h1>
            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                Recibimos una solicitud para cambiar la contraseña de tu cuenta.
                Pulsa el botón y elige una nueva.
            </p>
        </td>
    </tr>

    {{-- Botón --}}
    <tr>
        <td class="px" align="center" style="padding:26px 36px 6px 36px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                <td align="center" bgcolor="#4f46e5" style="border-radius:10px;">
                    <a href="{{ $resetUrl }}" target="_blank"
                       style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:10px;">
                        Crear mi nueva contraseña &rarr;
                    </a>
                </td>
            </tr></table>
        </td>
    </tr>

    {{-- Caducidad. Se dice el plazo para que quien abra el correo tarde entienda por qué el enlace
         ya no le sirve, en vez de creer que el sistema falla. --}}
    <tr>
        <td class="px" align="center" style="padding:10px 36px 0 36px;">
            <p style="margin:0; font-size:13px; color:#6b7280;">
                El enlace caduca en {{ $expiresInMinutes }} minutos y solo se puede usar una vez.
            </p>
        </td>
    </tr>

    {{-- Aviso de seguridad: si no fue él, no tiene que hacer NADA. Es la instrucción correcta y la
         que evita que alguien entre en pánico o cambie su contraseña sin necesidad. --}}
    <tr>
        <td class="px" style="padding:24px 36px 0 36px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background-color:#f6f7fb; border-radius:12px;">
                <tr>
                    <td style="padding:14px 18px;">
                        <p style="margin:0; font-size:13px; line-height:1.6; color:#4b5162;">
                            <strong style="color:#1e2230;">¿No fuiste tú?</strong>
                            No tienes que hacer nada: tu contraseña sigue siendo la misma mientras no
                            uses este enlace.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Alternativa para clientes de correo que no dejan pulsar el botón. --}}
    <tr>
        <td class="px" style="padding:18px 36px 0 36px;">
            <p style="margin:0 0 4px 0; font-size:12px; color:#6b7280;">
                Si el botón no funciona, copia esta dirección en tu navegador:
            </p>
            <p style="margin:0; font-size:12px; line-height:1.5; word-break:break-all;">
                <a href="{{ $resetUrl }}" target="_blank" style="color:#4f46e5; text-decoration:underline;">{{ $resetUrl }}</a>
            </p>
        </td>
    </tr>
</x-mail.layout>
