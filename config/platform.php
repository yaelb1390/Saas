<?php

/*
 * Datos del operador de la plataforma (BM Business OS) que se muestran a las empresas cuando su
 * cuenta queda suspendida, para que puedan regularizar el pago.
 */
return [
    'name' => env('PLATFORM_NAME', 'BM Business OS'),
    'support_whatsapp' => env('PLATFORM_SUPPORT_WHATSAPP', '18090000000'),
    'support_email' => env('PLATFORM_SUPPORT_EMAIL', 'soporte@bmbusiness.os'),

    // Enlace de pago de PayPal (paypal.me/usuario o un botón/checkout). Vacío = no se muestra el
    // botón de PayPal. No hay integración con la API de PayPal: es un enlace de cobro manual.
    'support_paypal' => env('PLATFORM_SUPPORT_PAYPAL', ''),
];
