<?php

declare(strict_types=1);

namespace App\Modules\Social\Exceptions;

use DomainException;

/**
 * Fallos de las redes sociales, dichos para que el dueño de un colmado sepa qué hacer.
 *
 * Nada de «error 502 del proveedor»: eso no le dice a nadie si el problema es suyo, nuestro o de
 * Instagram, ni si debe volver a intentarlo o llamar por teléfono.
 */
final class SocialException extends DomainException
{
    public static function sinClave(): self
    {
        return new self('Todavía no has conectado tu cuenta de Zernio. Pega tu clave en esta misma pantalla.');
    }

    public static function noSePudoConectar(string $red): self
    {
        return new self("No pudimos abrir la conexión con {$red}. Inténtalo de nuevo en un momento.");
    }

    public static function noRespondio(): self
    {
        return new self('No pudimos hablar con el servicio de redes. Puede ser tu clave o una caída suya; inténtalo de nuevo.');
    }

    public static function sinCuentas(): self
    {
        return new self('Elige al menos una red donde publicar.');
    }

    /**
     * La dirección donde recibiríamos los avisos no existe fuera de este equipo.
     *
     * El mensaje nombra la dirección concreta porque sin ella no se entiende nada: ver «localhost»
     * escrito es lo que hace caer la ficha. Y explica la consecuencia —los mensajes se entregan en
     * otro sitio— porque el síntoma es «no llega nada» y eso no apunta a ninguna causa.
     */
    public static function webhookInalcanzable(string $url): self
    {
        return new self(
            "No se puede activar aquí: los mensajes se reciben en «{$url}», y esa dirección solo "
            .'existe dentro de este equipo. El servicio de redes está en internet y no puede '
            .'alcanzarla, así que los mensajes se entregarían donde esté publicado el sistema. '
            .'Actívalo desde ahí, o publica este equipo con un túnel y vuelve a intentarlo.'
        );
    }

    public static function noSePudoPublicar(?string $motivo = null): self
    {
        // El motivo de Zernio es mucho más útil que el nuestro cuando existe: «el vídeo excede la
        // duración de TikTok» se puede arreglar, «no se pudo publicar» no.
        return new self($motivo !== null && $motivo !== ''
            ? "No se pudo publicar: {$motivo}"
            : 'No se pudo publicar. Revisa el texto y las cuentas elegidas, e inténtalo de nuevo.');
    }

    public static function noSePudoSubirLaFoto(): self
    {
        return new self('No pudimos preparar la subida de la imagen. Inténtalo de nuevo.');
    }

    public static function noSeGuardoLaAutomatizacion(?string $motivo = null): self
    {
        // El motivo de Zernio suele ser concreto —una cuenta que no admite automatizaciones, un
        // mensaje demasiado largo— y eso se puede corregir; «no se pudo guardar» no.
        return new self($motivo !== null && $motivo !== ''
            ? "No se pudo guardar la respuesta automática: {$motivo}"
            : 'No se pudo guardar la respuesta automática. Revisa los datos e inténtalo de nuevo.');
    }
}
