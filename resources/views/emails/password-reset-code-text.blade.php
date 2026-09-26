Hola, {{ $ownerName }}

Recibimos una solicitud para cambiar la contraseña de tu cuenta.
Escribe este código en la pantalla donde lo pidieron:

{{ $code }}

Este código caduca en {{ $expiresInMinutes }} minutos y solo se puede usar una vez.

¿No fuiste tú? No tienes que hacer nada: tu contraseña sigue siendo la misma
mientras no uses este código.

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}
@if (filled(config('mail.from.address')))
Para no perder nuestros correos, añade {{ config('mail.from.address') }} a tus contactos.
@endif

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
