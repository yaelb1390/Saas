¡Qué bueno que te quedas, {{ $firstName }}!

Reactivaste la suscripción de "{{ $companyName }}". Todo sigue como antes: no perdiste nada.

PLAN {{ $planName }}
RD$ {{ number_format((float) $planPrice, 2) }} · {{ $billingCycleLabel }}
Próxima renovación: {{ $renewsAt->format('d/m/Y') }}

Ir a mi cuenta:
{{ $accountUrl }}

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
