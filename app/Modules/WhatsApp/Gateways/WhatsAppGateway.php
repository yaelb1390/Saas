<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Gateways;

/**
 * Abstracción del proveedor de WhatsApp. Permite intercambiar Evolution API por otro proveedor
 * (o por un doble en tests) sin tocar la lógica de negocio.
 */
interface WhatsAppGateway
{
    /**
     * Envía un mensaje de texto.
     *
     * @return array{external_id: ?string, status: string}
     */
    public function sendText(string $phone, string $body): array;

    /**
     * ¿Esta vía sabe mandar archivos?
     *
     * Se pregunta en vez de mirar de qué clase es el gateway: cuando mañana haya un proveedor más,
     * el único que sabe si adjunta o no es él mismo. Quien llama usa esto para decidir si manda el
     * PDF o se conforma con el enlace.
     */
    public function puedeEnviarDocumentos(): bool;

    /**
     * Envía un archivo por su DIRECCIÓN, no por su contenido.
     *
     * Una URL y no los bytes porque es lo que aceptan los proveedores —Evolution lo dice tal cual:
     * «media must be a url or base64»— y porque en producción no hay dónde guardar el archivo: el
     * disco es de solo lectura. Generándolo al vuelo detrás de un enlace firmado, además, nunca se
     * manda una versión vieja.
     *
     * @param  string  $url  de dónde lo baja el proveedor; debe ser accesible desde su servidor
     * @param  string  $fileName  cómo se llamará en el chat del cliente
     * @param  string  $caption  el texto que acompaña al archivo
     * @return array{external_id: ?string, status: string}
     */
    public function sendDocument(string $phone, string $url, string $fileName, string $caption = ''): array;
}
