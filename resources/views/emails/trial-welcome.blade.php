{{-- Correo de bienvenida (registro self-service).
     A PRUEBA DE CLIENTES: solo tablas + estilos inline + fuentes del sistema. Nada de flex/grid,
     CSS externo ni webfonts (Gmail/Outlook no los soportan). El logo va por URL absoluta; si el
     cliente bloquea imágenes, el texto alterno mantiene la marca. --}}
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>Bienvenido a BM Business OS</title>
    <style>
        /* Solo mejoras progresivas; el correo funciona sin esto. */
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
    {{-- Preheader (texto de vista previa en la bandeja; oculto en el cuerpo). --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:#eef1f7; font-size:1px; line-height:1px;">
        Tu prueba gratuita de {{ $trialDays }} días en BM Business OS ya está activa.
    </div>

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

                    {{-- Saludo + confirmación --}}
                    <tr>
                        <td class="px" style="padding:14px 36px 0 36px;">
                            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                                ¡Hola, {{ $ownerName }}!
                            </h1>
                            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                                Tu negocio <strong style="color:#1e2230;">{{ $companyName }}</strong> quedó registrado en
                                BM Business OS. Tu prueba gratuita ya está activa. 🎉
                            </p>
                        </td>
                    </tr>

                    {{-- Bloque de la prueba --}}
                    <tr>
                        <td class="px" style="padding:20px 36px 0 36px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="background-color:#eef2ff; border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 18px;">
                                        <p style="margin:0 0 3px 0; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; color:#6366f1; font-weight:bold;">
                                            Prueba gratuita
                                        </p>
                                        <p style="margin:0; font-size:16px; color:#1e2230; font-weight:bold;">
                                            {{ $trialDays }} días · termina el {{ $trialEndsAt->format('d/m/Y') }}
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Módulos activados --}}
                    @if (! empty($moduleLabels))
                        <tr>
                            <td class="px" style="padding:20px 36px 0 36px;">
                                <p style="margin:0 0 8px 0; font-size:13px; color:#6b7280; font-weight:bold;">
                                    Módulos que activaste
                                </p>
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td>
                                    @foreach ($moduleLabels as $label)
                                        <span style="display:inline-block; margin:0 6px 6px 0; padding:5px 11px; background-color:#f1f5f9; border:1px solid #e2e8f0; border-radius:999px; font-size:12px; color:#334155;">{{ $label }}</span>
                                    @endforeach
                                </td></tr></table>
                            </td>
                        </tr>
                    @endif

                    {{-- Aviso: datos de prueba --}}
                    <tr>
                        <td class="px" style="padding:20px 36px 0 36px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="background-color:#fffbeb; border-radius:12px;">
                                <tr>
                                    <td style="padding:14px 16px; border-left:4px solid #f59e0b; font-size:13px; line-height:1.55; color:#92400e;">
                                        <strong>Modo de prueba.</strong> Los datos que registres son de prueba y se
                                        <strong>eliminarán el {{ $purgeAt->format('d/m/Y') }}</strong> (24&nbsp;horas después de que
                                        termine tu prueba) si no activas un plan.
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
                                    <a href="{{ $loginUrl }}" target="_blank"
                                       style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:10px;">
                                        Entrar a mi panel &rarr;
                                    </a>
                                </td>
                            </tr></table>
                        </td>
                    </tr>

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
