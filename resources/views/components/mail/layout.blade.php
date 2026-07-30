{{-- Armazón compartido de los correos de BM Business OS.
     A PRUEBA DE CLIENTES: solo tablas + estilos inline + fuentes del sistema. El cuerpo de cada correo
     se pasa en el $slot (filas <tr>). El logo va por URL absoluta; si el cliente bloquea imágenes, el
     texto alterno mantiene la marca. --}}
@props(['preheader' => '', 'supportWhatsapp' => '', 'supportEmail' => ''])
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>BM Business OS</title>
    <style>
        body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; }
        img { border: 0; line-height: 100%; outline: none; text-decoration: none; }
        a { color: #4f46e5; }
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; }
            .px { padding-left: 22px !important; padding-right: 22px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#eef1f7;">
    @if ($preheader !== '')
        <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:#eef1f7; font-size:1px; line-height:1px;">{{ $preheader }}</div>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef1f7;">
        <tr>
            <td align="center" style="padding:28px 12px;">
                <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0"
                       style="width:600px; max-width:600px; background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 1px 3px rgba(16,24,40,0.08); font-family:Arial,Helvetica,sans-serif;">

                    {{-- Barra de acento superior --}}
                    <tr><td style="height:5px; background-color:#4f46e5; background:linear-gradient(90deg,#4f46e5,#7c3aed); line-height:5px; font-size:5px;">&nbsp;</td></tr>

                    {{-- Cabecera con el logo --}}
                    <tr>
                        <td align="center" style="padding:30px 30px 8px 30px;">
                            <img src="{{ asset('images/bm-logo.png') }}" alt="BM Business OS" width="190"
                                 style="display:block; width:190px; max-width:60%; height:auto; margin:0 auto;">
                        </td>
                    </tr>

                    {{-- Cuerpo específico de cada correo --}}
                    {{ $slot }}

                    {{-- Pie --}}
                    <tr>
                        <td class="px" style="padding:24px 36px 30px 36px;">
                            <hr style="border:none; border-top:1px solid #eceef4; margin:0 0 16px 0;">
                            <p style="margin:0 0 4px 0; font-size:13px; line-height:1.6; color:#6b7280;">
                                ¿Necesitas ayuda? Escríbenos por
                                <a href="https://wa.me/{{ preg_replace('/\D/', '', $supportWhatsapp) }}" style="color:#4f46e5; text-decoration:none;">WhatsApp</a>
                                o a <a href="mailto:{{ $supportEmail }}" style="color:#4f46e5; text-decoration:none;">{{ $supportEmail }}</a>.
                            </p>
                            <p style="margin:12px 0 0 0; font-size:11px; color:#9aa1b0;">
                                © {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
