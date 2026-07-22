<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>La página caducó · BM Business OS</title>
    @if (file_exists(public_path('images/bm-mark.png')))
        <link rel="icon" type="image/png" href="{{ asset('images/bm-mark.png') }}">
    @endif
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background-color: #0B1120; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { background-color: #FFFFFF; width: 100%; max-width: 440px; border-radius: 8px; border: 1px solid #E5E7EB;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,.7), 0 10px 20px -5px rgba(0,0,0,.5); padding: 40px; text-align: center; }
        .icon { display: inline-flex; align-items: center; justify-content: center; width: 56px; height: 56px;
            background-color: #FEF3C7; border-radius: 50%; margin-bottom: 24px; }
        .icon svg { width: 28px; height: 28px; fill: #B45309; }
        .badge { font-size: 12px; font-weight: 700; color: #B45309; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 12px; display: block; }
        h1 { font-size: 22px; font-weight: 700; color: #111827; margin-bottom: 16px; }
        p { font-size: 14px; line-height: 1.6; color: #4B5563; margin-bottom: 32px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 22px;
            border-radius: 6px; font-size: 14px; font-weight: 500; text-decoration: none; color: #FFFFFF;
            background-color: #4F46E5; transition: background-color .2s ease; }
        .btn:hover { background-color: #4338CA; }
        .footer { margin-top: 32px; font-size: 12px; color: rgba(255,255,255,.4); position: fixed; bottom: 24px; left: 0; right: 0; text-align: center; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
        </div>
        <span class="badge">Sesión caducada</span>
        <h1>La página expiró por seguridad</h1>
        <p>
            Por tu seguridad, la sesión se cerró tras un período de inactividad. Vuelve a iniciar sesión
            para continuar; tus datos están a salvo.
        </p>
        <a href="{{ route('login') }}" class="btn">Volver a iniciar sesión</a>
    </div>
    <div class="footer">© {{ date('Y') }} BM Business OS</div>
</body>
</html>
