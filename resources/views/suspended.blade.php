<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Acceso Suspendido · {{ $platformName }}</title>
    @if (file_exists(public_path('images/bm-mark.png')))
        <link rel="icon" type="image/png" href="{{ asset('images/bm-mark.png') }}">
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Paleta de colores corporativa */
            --bg-corporate: #0B1120;
            --card-bg: #FFFFFF;
            --text-primary: #111827;
            --text-secondary: #4B5563;
            --border-color: #E5E7EB;
            --warning-bg: #FEF3C7;
            --warning-text: #B45309;
            --btn-wa: #059669;
            --btn-wa-hover: #047857;
            --btn-mail: #4F46E5;
            --btn-mail-hover: #4338CA;
            --link-color: #6B7280;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: var(--bg-corporate);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .auth-card {
            background-color: var(--card-bg);
            width: 100%;
            max-width: 460px;
            border-radius: 8px;
            /* Sombra corporativa profunda multicapa */
            box-shadow:
                0 25px 50px -12px rgba(0, 0, 0, 0.7),
                0 10px 20px -5px rgba(0, 0, 0, 0.5);
            border: 1px solid var(--border-color);
            padding: 40px;
            text-align: center;
        }

        .icon-container {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            background-color: var(--warning-bg);
            border-radius: 50%;
            margin-bottom: 24px;
        }

        .icon-container svg {
            width: 28px;
            height: 28px;
            fill: var(--warning-text);
        }

        .badge-status {
            font-size: 12px;
            font-weight: 700;
            color: var(--warning-text);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 12px;
            display: block;
        }

        .title {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 16px;
        }

        .description {
            font-size: 14px;
            line-height: 1.6;
            color: var(--text-secondary);
            margin-bottom: 32px;
        }

        .description strong {
            color: var(--text-primary);
            font-weight: 600;
        }

        .actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 24px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            color: #FFFFFF;
            transition: background-color 0.2s ease;
            border: none;
            cursor: pointer;
        }

        .btn svg {
            width: 18px;
            height: 18px;
            fill: currentColor;
        }

        .btn-whatsapp {
            background-color: var(--btn-wa);
        }

        .btn-whatsapp:hover {
            background-color: var(--btn-wa-hover);
        }

        .btn-email {
            background-color: var(--btn-mail);
        }

        .btn-email:hover {
            background-color: var(--btn-mail-hover);
        }

        .support-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .support-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .support-item svg {
            width: 14px;
            height: 14px;
            fill: var(--text-secondary);
        }

        /* Botón "Cerrar sesión" moderno (ghost button) */
        .logout {
            background-color: transparent;
            border: 1px solid transparent;
            padding: 8px 20px;
            border-radius: 6px;
            color: #3b82f6;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
            transition: all 0.2s ease;
            display: inline-block;
        }

        .logout:hover {
            background-color: rgba(59, 130, 246, 0.08);
            color: #2563eb;
            border-color: rgba(59, 130, 246, 0.2);
        }

        .logout:active {
            background-color: rgba(59, 130, 246, 0.15);
            transform: scale(0.98);
        }

        .footer {
            margin-top: 32px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.4);
        }

        @media (max-width: 480px) {
            .auth-card {
                padding: 32px 20px;
            }
            .actions {
                grid-template-columns: 1fr;
            }
            .support-info {
                flex-direction: column;
                gap: 12px;
            }
        }
    </style>
</head>
<body>
@php
    $waDigits = preg_replace('/\D/', '', $whatsapp);
    $waText = rawurlencode('Hola, soy de «'.($company?->name ?? 'mi empresa').'». Mi cuenta está suspendida y quiero regularizar el pago.');
    $mailSubject = rawurlencode('Reactivar cuenta suspendida');
@endphp

    <div class="auth-card">

        <div class="icon-container">
            <svg viewBox="0 0 24 24">
                <path d="M12 17a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm6-9a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V10a2 2 0 0 1 2-2h1V6a5 5 0 0 1 10 0v2h1zm-6-5a3 3 0 0 0-3 3v2h6V6a3 3 0 0 0-3-3z"/>
            </svg>
        </div>

        <span class="badge-status">Acceso Pausado</span>

        <h1 class="title">Tu cuenta está suspendida</h1>

        <p class="description">
            {{ $reason }} El acceso a <strong>{{ $company?->name ?? 'tu empresa' }}</strong> quedó suspendido por falta de pago. Para reactivarlo, comunícate con el administrador de la plataforma.
        </p>

        <div class="actions">
            <a href="https://wa.me/{{ $waDigits }}?text={{ $waText }}" target="_blank" rel="noopener" class="btn btn-whatsapp">
                <svg viewBox="0 0 24 24">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/>
                </svg>
                WhatsApp
            </a>

            <a href="mailto:{{ $email }}?subject={{ $mailSubject }}" class="btn btn-email">
                <svg viewBox="0 0 24 24">
                    <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                </svg>
                Correo Electrónico
            </a>
        </div>

        <div class="support-info">
            <div class="support-item">
                <svg viewBox="0 0 24 24">
                    <path d="M20 15.5c-1.25 0-2.45-.2-3.57-.57a1.02 1.02 0 0 0-1.02.24l-2.2 2.2a15.045 15.045 0 0 1-6.59-6.59l2.2-2.21c.28-.26.36-.65.25-1C8.7 6.45 8.5 5.25 8.5 4c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1 0 9.39 7.61 17 17 17 .55 0 1-.45 1-1v-3.5c0-.55-.45-1-1-1zM19 12h2a9 9 0 0 0-9-9v2c3.87 0 7 3.13 7 7zm-4 0h2c0-2.76-2.24-5-5-5v2c1.66 0 3 1.34 3 3z"/>
                </svg>
                {{ $whatsapp }}
            </div>
            <div class="support-item">
                <svg viewBox="0 0 24 24">
                    <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                </svg>
                {{ $email }}
            </div>
        </div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="logout">Cerrar sesión</button>
        </form>

    </div>

    <div class="footer">
        © {{ date('Y') }} {{ $platformName }}
    </div>

</body>
</html>
