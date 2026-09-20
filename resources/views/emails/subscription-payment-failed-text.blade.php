Hola, {{ $firstName }}

No pudimos cobrar la renovación de la suscripción de "{{ $companyName }}". Suele pasar porque la tarjeta venció, no tiene fondos o el banco bloqueó el cobro.

COBRO PENDIENTE
RD$ {{ number_format((float) $planPrice, 2) }} · {{ $billingCycleLabel }}
Plan {{ $planName }}

Cuando actualices tu método de pago se volverá a intentar el cobro. Si el problema no se resuelve, la suscripción se cancelará y perderás el acceso a tu cuenta. Tus datos no se borran: puedes volver cuando quieras.

Actualizar mi tarjeta:
{{ $updateCardUrl }}

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}
@if (filled(config('mail.from.address')))
Para no perder nuestros correos, añade {{ config('mail.from.address') }} a tus contactos.
@endif

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
