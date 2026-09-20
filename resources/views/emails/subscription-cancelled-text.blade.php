Hola, {{ $firstName }}

Recibimos la cancelación de la suscripción de "{{ $companyName }}". Lamentamos que te vayas, y queremos que sepas que no pierdes nada de lo que ya pagaste.

TU ACCESO SIGUE ACTIVO
Hasta el {{ $accessUntil->format('d/m/Y') }}@if ($daysLeft > 1) · te quedan {{ $daysLeft }} días @elseif ($daysLeft === 1) · te queda 1 día @endif

Hasta esa fecha sigues usando el plan {{ $planName }} con todos sus módulos. Después no se cobrará ni se renovará más.

Tus datos están a salvo. No borramos nada de tu empresa: si más adelante quieres volver, solo tienes que contratar de nuevo y sigues donde lo dejaste.

¿Cambiaste de idea? Puedes reactivar tu suscripción cuando quieras hasta el {{ $accessUntil->format('d/m/Y') }}:
{{ $accountUrl }}

¿Nos cuentas por qué cancelas? Responde a este correo o escríbenos por WhatsApp. Tu opinión nos ayuda a mejorar.

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
