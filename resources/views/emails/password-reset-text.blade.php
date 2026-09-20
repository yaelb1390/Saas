Hola, {{ $ownerName }}

Recibimos una solicitud para cambiar la contraseña de tu cuenta.
Abre esta dirección para elegir una nueva:

{{ $resetUrl }}

El enlace caduca en {{ $expiresInMinutes }} minutos y solo se puede usar una vez.

¿No fuiste tú? No tienes que hacer nada: tu contraseña sigue siendo la misma
mientras no uses este enlace.

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}
@if (filled(config('mail.from.address')))
Para no perder nuestros correos, añade {{ config('mail.from.address') }} a tus contactos.
@endif

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
