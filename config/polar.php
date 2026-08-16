<?php

/*
 * Configuración de Polar (la pasarela que cobra las suscripciones).
 *
 * El secreto del webhook lo genera Polar al crear el endpoint y NO es el token de la API: son dos
 * credenciales distintas y no son intercambiables. Sin secreto configurado, el webhook rechaza todo:
 * nunca se acepta un aviso de pago sin firmar, porque quien pudiera enviarlo se regalaría el acceso
 * a sí mismo activando la suscripción que quisiera.
 */
return [
    /*
     * Token de la API, para crear los cobros. Es DISTINTO del secreto del webhook: aquel verifica lo
     * que entra, este autoriza lo que sale. Sin token, el botón de pago no se muestra y la pantalla
     * de suscripción solo ofrece contacto.
     */
    'access_token' => env('POLAR_ACCESS_TOKEN'),

    /*
     * «sandbox» para probar con tarjetas de prueba; «production» para cobrar de verdad.
     *
     * Se guarda el entorno y no la URL suelta: una URL mal tecleada apuntaría a un sitio inexistente
     * o —peor— haría cobros reales creyendo que se está probando.
     */
    'server' => env('POLAR_SERVER', 'production'),

    /*
     * Idioma de la pantalla de pago. Vacío = no se manda y Polar decide por el navegador.
     *
     * APAGADO POR DEFECTO A PROPÓSITO. La localización del checkout es una función en BETA que hay
     * que habilitar en la organización de Polar; si se manda el idioma sin haberla habilitado y Polar
     * rechazara el parámetro, dejarían de funcionar TODOS los cobros. Un interruptor propio hace que
     * eso solo pueda pasar cuando alguien lo enciende a sabiendas, y que se apague en un segundo.
     *
     * Para encenderlo: habilitar la localización en Polar y poner POLAR_LOCALE=es.
     *
     * Durante la beta, Polar traduce la pantalla de pago pero NO los mensajes de error ni los correos
     * de recibo, que siguen llegando en inglés.
     */
    'locale' => env('POLAR_LOCALE'),

    'webhook_secret' => env('POLAR_WEBHOOK_SECRET'),

    // Margen del sello de tiempo de la firma, en segundos. Descarta reenvíos antiguos: si alguien
    // captura un aviso legítimo, no puede repetirlo pasado este plazo.
    'webhook_tolerance' => (int) env('POLAR_WEBHOOK_TOLERANCE', 300),
];
