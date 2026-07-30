Hola, {{ $ownerName }}

Tu suscripción de "{{ $companyName }}" está por vencer. Renueva a tiempo para no perder el acceso.

PLAN {{ $planName }}
@if ($daysLeft <= 0)
Vence hoy ({{ $renewsAt->format('d/m/Y') }})
@else
Vence el {{ $renewsAt->format('d/m/Y') }} · faltan {{ $daysLeft }} {{ $daysLeft === 1 ? 'día' : 'días' }}
@endif

Para renovar, escríbenos por WhatsApp o correo y te ayudamos a mantener tu cuenta activa.

Ver mi suscripción:
{{ $loginUrl }}

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
