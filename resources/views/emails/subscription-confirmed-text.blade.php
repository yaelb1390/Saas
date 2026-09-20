¡Gracias, {{ $ownerName }}!

La suscripción de "{{ $companyName }}" quedó confirmada. Ya tienes acceso completo a tu plan.

PLAN {{ $planName }}
RD$ {{ number_format((float) $planPrice, 2) }} · {{ $billingCycleLabel }}
Próxima renovación: {{ $renewsAt->format('d/m/Y') }}
@if (! empty($moduleLabels))

Módulos incluidos: {{ implode(', ', $moduleLabels) }}
@endif

Ir a mi panel:
{{ $loginUrl }}

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}
@if (filled(config('mail.from.address')))
Para no perder nuestros correos, añade {{ config('mail.from.address') }} a tus contactos.
@endif

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
