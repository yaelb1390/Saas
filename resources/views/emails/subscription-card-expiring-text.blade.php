Hola, {{ $firstName }}

@if ($failsAtRenewal)
La tarjeta con la que se paga la suscripción de "{{ $companyName }}" vence en {{ $cardExpiry }}, antes de la próxima renovación. Si no la actualizas, el cobro fallará y perderás el acceso.
@else
La tarjeta con la que se paga la suscripción de "{{ $companyName }}" vence en {{ $cardExpiry }}. Todavía sirve para la próxima renovación, pero conviene cambiarla ya para que el cobro siguiente no falle.
@endif

TARJETA POR VENCER
{{ $cardName }} · vence {{ $cardExpiry }}

Próxima renovación: {{ $renewsAt->format('d/m/Y') }} · RD$ {{ number_format((float) $planPrice, 2) }} ({{ mb_strtolower($billingCycleLabel) }}). Plan {{ $planName }}.

Actualizar mi tarjeta (te llevamos a la página segura de pagos):
{{ $updateCardUrl }}

¿No quieres seguir? Puedes cancelar la suscripción desde tu panel antes de esa fecha y conservas el acceso hasta el fin del período:
{{ $accountUrl }}

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}
@if (filled(config('mail.from.address')))
Para no perder nuestros correos, añade {{ config('mail.from.address') }} a tus contactos.
@endif

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
