Hola, {{ $firstName }}

Te avisamos con tiempo: la suscripción de "{{ $companyName }}" se renovará automáticamente. No tienes que hacer nada.

RENOVACIÓN AUTOMÁTICA
{{ $renewsAt->format('d/m/Y') }}@if ($daysLeft > 1) · en {{ $daysLeft }} días @elseif ($daysLeft === 1) · mañana @elseif ($daysLeft === 0) · hoy @endif

Se cobrarán RD$ {{ number_format((float) $planPrice, 2) }} ({{ mb_strtolower($billingCycleLabel) }}) a la tarjeta con la que pagaste. Plan {{ $planName }}.

¿No quieres renovar? Cancela la suscripción desde tu panel antes de esa fecha y conservas el acceso hasta el fin del período.

Administrar mi suscripción:
{{ $accountUrl }}

¿Cambió tu tarjeta o vence pronto? Actualízala aquí:
{{ $updateCardUrl }}

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}
@if (filled(config('mail.from.address')))
Para no perder nuestros correos, añade {{ config('mail.from.address') }} a tus contactos.
@endif

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
