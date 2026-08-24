<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Services;

use App\Modules\Quotes\Models\Quote;
use App\Modules\WhatsApp\Gateways\WhatsAppGateway;
use App\Modules\WhatsApp\Services\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Hacerle llegar la cotización al cliente.
 *
 * Hay TRES caminos y no uno, porque lo que se puede mandar depende del proveedor de cada empresa:
 *
 * 1. **El PDF adjunto.** Solo por la vía del emparejamiento por QR (Evolution). Comprobado contra su
 *    servidor: `/message/sendMedia` acepta un `media` que sea una URL, así que se le pasa la
 *    dirección firmada del PDF y él lo descarga. No hace falta guardar el archivo en ninguna parte,
 *    que en producción sería imposible: el disco es de solo lectura.
 *
 * 2. **El enlace, como texto.** Cuando el proveedor no adjunta.
 *
 * 3. **`wa.me`.** Abre WhatsApp en el móvil de quien vende, con el mensaje ya escrito. No es el
 *    premio de consolación: es el ÚNICO que funciona cuando el cliente nunca ha escrito a la
 *    empresa, y por la vía oficial de Meta eso no es un fallo del sistema sino la regla —solo se
 *    puede contestar a quien escribió primero—. La pantalla lo ofrece siempre.
 *
 * El enlace es firmado y temporal. Un chat se reenvía, y una dirección que no caduca es una puerta
 * abierta a lo que se le ofertó a otra persona.
 */
final class QuoteDelivery
{
    /**
     * Cuánto vive el enlace.
     *
     * Treinta días y no siete como el portal del cliente: una cotización se consulta más tarde y con
     * más calma —el cliente la enseña a un socio, la compara, vuelve a los diez días—. Aun así
     * caduca, porque el precio de dentro también caduca.
     */
    public const DIAS_DE_ENLACE = 30;

    public function __construct(private readonly WhatsAppService $whatsapp) {}

    /**
     * Manda la cotización por WhatsApp desde el sistema.
     *
     * Devuelve qué se hizo, para poder decírselo a quien pulsó el botón. «Enviado» a secas no vale:
     * no es lo mismo que le haya llegado el PDF que un enlace.
     */
    public function enviar(Quote $quote): string
    {
        $telefono = trim((string) $quote->customer_phone);

        if ($telefono === '') {
            throw new RuntimeException('Esta cotización no tiene teléfono al que enviarla.');
        }

        $enlace = $this->enlace($quote);

        if ($this->puedeAdjuntar()) {
            $this->whatsapp->sendDocument(
                $telefono,
                $this->enlacePdf($quote),
                'cotizacion-'.$quote->code.'.pdf',
                $this->mensaje($quote, $enlace),
            );

            return 'Se envió el PDF por WhatsApp.';
        }

        $this->whatsapp->sendText($telefono, $this->mensaje($quote, $enlace));

        return 'Se envió el enlace por WhatsApp.';
    }

    /**
     * ¿La vía de esta empresa admite archivos?
     *
     * Se le pregunta al gateway en vez de mirar de qué clase es: cuando mañana haya un tercer
     * proveedor, el que sabe si adjunta o no es él.
     */
    public function puedeAdjuntar(): bool
    {
        return app(WhatsAppGateway::class)->puedeEnviarDocumentos();
    }

    /** El enlace firmado a la página que ve el cliente. */
    public function enlace(Quote $quote): string
    {
        return URL::temporarySignedRoute(
            'quotes.public',
            Carbon::now()->addDays(self::DIAS_DE_ENLACE),
            ['quote' => $quote->id],
        );
    }

    /** El enlace firmado al PDF. Es el que se le pasa a WhatsApp para que lo descargue. */
    public function enlacePdf(Quote $quote): string
    {
        return URL::temporarySignedRoute(
            'quotes.public.pdf',
            Carbon::now()->addDays(self::DIAS_DE_ENLACE),
            ['quote' => $quote->id],
        );
    }

    /**
     * El enlace para abrir WhatsApp en el móvil de quien vende, con el texto puesto.
     *
     * Funciona siempre y no depende de ningún proveedor. Es como manda de verdad una cotización un
     * negocio pequeño: desde su propio WhatsApp, con su nombre y su foto.
     */
    public function enlaceWa(Quote $quote): string
    {
        $telefono = preg_replace('/\D+/', '', (string) $quote->customer_phone) ?? '';
        $texto = rawurlencode($this->mensaje($quote, $this->enlace($quote)));

        return $telefono === ''
            ? 'https://wa.me/?text='.$texto
            : 'https://wa.me/'.$telefono.'?text='.$texto;
    }

    /**
     * El mensaje.
     *
     * Lleva el importe y la fecha de validez en el propio texto, no solo dentro del enlace: mucha
     * gente decide con lo que ve en la notificación y no llega a abrir nada.
     */
    public function mensaje(Quote $quote, string $enlace): string
    {
        $lineas = [
            'Hola '.$quote->customer_name.', aquí tienes tu cotización '.$quote->code.'.',
            'Total: '.money((float) $quote->total),
        ];

        if ($quote->valid_until !== null) {
            $lineas[] = 'Válida hasta el '.$quote->valid_until->format('d/m/Y').'.';
        }

        $lineas[] = $enlace;

        return implode("\n", $lineas);
    }
}
