Hola, {{ $firstName }}

@if ($requestedByCustomer)
La suscripción de "{{ $companyName }}" terminó hoy, como pediste. Gracias por haber usado BM Business OS.
@else
La suscripción de "{{ $companyName }}" terminó y ya no tienes acceso a los módulos. Suele pasar cuando no se puede cobrar la renovación.
@endif

SUSCRIPCIÓN TERMINADA
Plan {{ $planName }}
Tu acceso a los módulos se retiró.

Tus datos están a salvo. No borramos nada de tu empresa: cuando quieras volver, contrata de nuevo y sigues donde lo dejaste.

Volver a contratar:
{{ $resubscribeUrl }}

¿Nos cuentas cómo podríamos mejorar? Responde a este correo o escríbenos por WhatsApp. Tu opinión nos ayuda.

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}
@if (filled(config('mail.from.address')))
Para no perder nuestros correos, añade {{ config('mail.from.address') }} a tus contactos.
@endif

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
