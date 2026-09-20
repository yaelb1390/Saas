¡Hola, {{ $ownerName }}!

Tu negocio "{{ $companyName }}" quedó registrado en BM Business OS y tu prueba gratuita ya está activa.

PRUEBA GRATUITA
{{ $trialDays }} días · termina el {{ $trialEndsAt->format('d/m/Y') }}
@if (! empty($moduleLabels))

Módulos que activaste: {{ implode(', ', $moduleLabels) }}
@endif

MODO DE PRUEBA
Los datos que registres son de prueba y se eliminarán el {{ $purgeAt->format('d/m/Y') }}
(24 horas después de que termine tu prueba) si no activas un plan.

Entra a tu panel:
{{ $loginUrl }}

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}
@if (filled(config('mail.from.address')))
Para no perder nuestros correos, añade {{ config('mail.from.address') }} a tus contactos.
@endif

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
