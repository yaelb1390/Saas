<?php

/*
 * Datos del operador de la plataforma (BM Business OS) que se muestran a las empresas cuando su
 * cuenta queda suspendida, para que puedan regularizar el pago.
 */
return [
    'name' => env('PLATFORM_NAME', 'BM Business OS'),
    // Por defecto, los contactos reales de soporte (los mismos que tiene producción en sus variables):
    // así un entorno sin estas variables no enseña datos de relleno a quien quiere pagar o pedir ayuda.
    'support_whatsapp' => env('PLATFORM_SUPPORT_WHATSAPP', '18296512447'),
    'support_email' => env('PLATFORM_SUPPORT_EMAIL', 'soporte@bm1390.cloud'),

    // Enlace de pago de PayPal (paypal.me/usuario o un botón/checkout). Vacío = no se muestra el
    // botón de PayPal. No hay integración con la API de PayPal: es un enlace de cobro manual.
    'support_paypal' => env('PLATFORM_SUPPORT_PAYPAL', ''),
];
