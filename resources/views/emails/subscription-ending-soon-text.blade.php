Hola, {{ $firstName }}

Te recordamos que la suscripción de "{{ $companyName }}" termina pronto, porque pediste la baja. Hasta esa fecha sigues con acceso completo.

TU ACCESO TERMINA
{{ $accessUntil->format('d/m/Y') }}@if ($daysLeft > 1) · en {{ $daysLeft }} días @elseif ($daysLeft === 1) · mañana @elseif ($daysLeft === 0) · hoy @endif

Después de esa fecha no podrás entrar a los módulos del plan {{ $planName }}.

¿Cambiaste de idea? Reactívala con un clic desde tu panel y todo seguirá como antes:
{{ $accountUrl }}

Tus datos están a salvo. Si prefieres irte, no borramos nada de tu empresa: cuando quieras volver, contratas de nuevo y sigues donde lo dejaste.

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}
@if (filled(config('mail.from.address')))
Para no perder nuestros correos, añade {{ config('mail.from.address') }} a tus contactos.
@endif

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
