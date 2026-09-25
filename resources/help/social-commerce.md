---
title: Social Commerce
module: social_commerce
permission: social_commerce.view
keywords: social commerce, instagram, comentarios, precio, automatico, respuesta automatica, palabra clave, plantilla, producto, zernio, whatsapp
related: redes-sociales, whatsapp, crm, inventario
route: panel.social-commerce.index
---

Contesta el precio en los comentarios de tu Instagram **al momento**, sin escribir cada respuesta a mano: eliges un producto, sus palabras clave y qué contesta, y el sistema hace el resto.

## Cómo funciona

Alguien comenta «precio» en una foto tuya. El sistema:

1. Reconoce la palabra clave.
2. Busca el producto que le asignaste a esa regla.
3. Contesta bajo el comentario y por privado, con el precio de verdad — el que tienes en **Inventario**, no uno escrito a mano que se te puede olvidar actualizar.

## Antes de empezar: conectar tu cuenta

En **Ajustes de conexión**, pega tu clave de Zernio (la misma que «Redes sociales», si ya la usas) y conecta tu cuenta de Instagram o Facebook. No hace falta tener contratado el módulo de Redes sociales para esto: Social Commerce se conecta por su cuenta.

También pon ahí tu **número de WhatsApp**: es al que llevan los botones de tus mensajes.

## Crear una regla

Pulsa «Nueva regla» y completa:

- **Producto**: de qué vas a hablar. El precio sale de aquí, no lo escribes tú.
- **Palabras clave**: qué tiene que decir el seguidor para que salte («precio», «cuánto», «vale»…).
- **Plantillas**: lo que contesta, en público y por privado. Puedes usar `{producto}`, `{precio}`, `{moneda}`, `{sku}`, `{categoria}`, `{url_whatsapp}` y `{nombre_cliente}` — se rellenan solas al guardar.
- Varias plantillas para el mismo mensaje ayudan a que no salga siempre el mismo texto (Instagram limita las cuentas que repiten el mismo mensaje una y otra vez).

## Lo que no se puede hacer (y por qué)

No hay forma de elegir «responde con la segunda plantilla, luego la tercera»: quien decide cuál de tus plantillas sale en cada comentario es Instagram (a través de Zernio), al azar, en el momento. No es una limitación de esta pantalla — es que no existe ningún botón en la API oficial para pedirlo de otra forma, y aquí no se inventan atajos por fuera de lo oficial.

## Quién puede hacer qué

- **Ver** las reglas no tiene peligro.
- **Gestionar** (crear, editar, borrar reglas) habla en nombre del negocio ante sus clientes.
- **Conectar** la cuenta mueve la credencial de Zernio.

El cajero no entra a esta pantalla.
