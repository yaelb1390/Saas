<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Gateways;

/**
 * Gestión del vínculo con la línea de WhatsApp (estado de sesión y emparejamiento por QR).
 *
 * Se separa de WhatsAppGateway (envío) siguiendo el principio de segregación de interfaces:
 * el dominio que envía mensajes no necesita conocer nada del ciclo de vida de la instancia.
 */
interface WhatsAppConnection
{
    /**
     * Estado actual de la línea.
     *
     * @return array{state: string, instance: string, connected: bool}
     */
    public function status(): array;

    /**
     * Inicia (o reanuda) el vínculo con la línea.
     *
     * Devuelve DOS formas posibles porque hay dos maneras de conectar, y son distintas de raíz:
     *
     *  · `qr`  — un código que se escanea con el teléfono (Evolution). Dos minutos y cualquier número.
     *  · `url` — una dirección a la que ir (la vía oficial de Meta). El negocio se va a Meta, elige su
     *            cuenta y su número, y vuelve conectado. No hay nada que escanear.
     *
     * Nunca vienen las dos: la que no aplica llega en null, y la pantalla pinta la que haya.
     *
     * @return array{state: string, qr: ?string, url: ?string}
     */
    public function connect(): array;

    /**
     * Desvincula el teléfono. Devuelve si el proveedor lo aceptó.
     *
     * El permiso `whatsapp.connect` dice desde el principio «vincula/desvincula la línea»: esta era
     * la mitad que no existía, y sin ella cambiar de número obligaba a entrar al panel de Evolution.
     */
    public function logout(): bool;
}
