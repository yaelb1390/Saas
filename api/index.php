<?php

declare(strict_types=1);

/**
 * Punto de entrada para Vercel (runtime serverless de PHP).
 *
 * Vercel enruta todas las peticiones a este archivo (ver vercel.json). Aquí solo delegamos al
 * front controller estándar de Laravel. Las rutas de caché/compilación de Laravel deben apuntar a
 * /tmp mediante variables de entorno (APP_*_CACHE, VIEW_COMPILED_PATH), porque en serverless el
 * único directorio escribible es /tmp.
 */

// El compilador de vistas necesita que el directorio destino exista.
$compiled = getenv('VIEW_COMPILED_PATH') ?: '/tmp/views';
if (! is_dir($compiled)) {
    mkdir($compiled, 0755, true);
}

require __DIR__.'/../public/index.php';
