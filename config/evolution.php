<?php

/*
 * Configuración de Evolution API (WhatsApp). Los valores se toman del .env.
 * Si base_url está vacío, el sistema usa un gateway de log (sin envío real).
 *
 * La instancia por defecto solo se usa si no hay empresa activa: en operación normal el nombre
 * de la instancia es el slug de la empresa, de modo que cada empresa tiene su propia línea.
 */
return [
    'base_url' => env('EVOLUTION_BASE_URL'),
    'api_key' => env('EVOLUTION_API_KEY'),
    'instance' => env('EVOLUTION_INSTANCE'),
    'webhook_secret' => env('EVOLUTION_WEBHOOK_SECRET'),

    // URL que Evolution invoca al recibir mensajes. Dentro de Docker, el host es el servicio web.
    'webhook_url' => env('EVOLUTION_WEBHOOK_URL', 'http://web/webhooks/evolution'),
];
