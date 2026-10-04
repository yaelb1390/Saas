{{-- La DGII rechazó un e-CF de producción. Cuerpo dentro del armazón compartido. --}}
<x-mail.layout preheader="Un comprobante electrónico fue rechazado por la DGII. Emite uno nuevo corregido."
               :supportWhatsapp="$supportWhatsapp" :supportEmail="$supportEmail">

    <tr>
        <td class="px" style="padding:14px 36px 0 36px;">
            <h1 style="margin:0 0 6px 0; font-size:22px; line-height:1.25; color:#1e2230; font-weight:bold;">
                Hola, {{ $ownerName }}
            </h1>
            <p style="margin:0; font-size:15px; line-height:1.6; color:#4b5162;">
                La DGII rechazó un comprobante electrónico de <strong style="color:#1e2230;">{{ $companyName }}</strong>.
                Ese documento no tiene validez fiscal: hay que emitir uno nuevo con los datos corregidos.
            </p>
        </td>
    </tr>

    <tr>
        <td class="px" style="padding:20px 36px 0 36px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background-color:#fef2f2; border-radius:12px;">
                <tr>
                    <td style="padding:16px 18px; border-left:4px solid #ef4444;">
                        <p style="margin:0 0 3px 0; font-size:12px; letter-spacing:0.4px; text-transform:uppercase; color:#b91c1c; font-weight:bold;">
                            {{ $typeLabel }} · {{ $encf }}
                        </p>
                        <p style="margin:0 0 6px 0; font-size:15px; color:#7f1d1d; font-weight:bold;">Total RD$ {{ $total }}</p>
                        <p style="margin:0; font-size:14px; line-height:1.5; color:#7f1d1d;">Motivo: {{ $reason }}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <tr>
        <td class="px" align="center" style="padding:24px 36px 6px 36px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                <td align="center" bgcolor="#4f46e5" style="border-radius:10px;">
                    <a href="{{ $documentUrl }}" target="_blank"
                       style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:10px;">
                        Ver el documento &rarr;
                    </a>
                </td>
            </tr></table>
        </td>
    </tr>
</x-mail.layout>
