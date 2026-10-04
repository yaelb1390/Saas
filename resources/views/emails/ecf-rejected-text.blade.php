Hola, {{ $ownerName }}

La DGII rechazó un comprobante electrónico de "{{ $companyName }}". Ese documento no tiene validez fiscal: hay que emitir uno nuevo con los datos corregidos.

{{ $typeLabel }} · {{ $encf }}
Total RD$ {{ $total }}
Motivo: {{ $reason }}

Ver el documento:
{{ $documentUrl }}

¿Necesitas ayuda? WhatsApp: {{ $supportWhatsapp }} · Correo: {{ $supportEmail }}
@if (filled(config('mail.from.address')))
Para no perder nuestros correos, añade {{ config('mail.from.address') }} a tus contactos.
@endif

© {{ date('Y') }} BM Business OS · Gestiona, conecta, crece.
