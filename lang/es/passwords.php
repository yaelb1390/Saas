<?php

declare(strict_types=1);

/*
 * Mensajes del flujo de recuperación de contraseña.
 *
 * Sin este archivo, con el idioma en español Laravel no encuentra la clave y muestra el
 * identificador crudo —«passwords.sent»— en vez de una frase. No se ve en inglés, se ve roto.
 *
 * `sent` y `user` dicen deliberadamente LO MISMO: el controlador responde igual exista o no la
 * cuenta, para que nadie pueda averiguar qué correos están registrados probando uno por uno.
 */
return [
    'reset' => 'Tu contraseña se cambió correctamente.',
    'sent' => 'Si ese correo tiene una cuenta, te enviamos un enlace para crear una contraseña nueva.',
    'throttled' => 'Espera un momento antes de volver a intentarlo.',
    'token' => 'Este enlace ya no es válido. Pide uno nuevo desde «¿Olvidaste tu contraseña?».',
    'user' => 'Si ese correo tiene una cuenta, te enviamos un enlace para crear una contraseña nueva.',
];
