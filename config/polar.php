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
    'webhook_secret' => env('POLAR_WEBHOOK_SECRET'),

    // Margen del sello de tiempo de la firma, en segundos. Descarta reenvíos antiguos: si alguien
    // captura un aviso legítimo, no puede repetirlo pasado este plazo.
    'webhook_tolerance' => (int) env('POLAR_WEBHOOK_TOLERANCE', 300),
];
