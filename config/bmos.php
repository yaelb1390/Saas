<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Prueba gratuita (registro self-service)
    |--------------------------------------------------------------------------
    |
    | Un cliente puede registrarse solo para una prueba. `days` es la duración, IGUAL para todos los
    | planes: el cliente elige el plan que quiere probar, y esa elección cambia qué módulos ve, no
    | cuántos días dispone. La pantalla de registro anuncia estos días, así que hacerlos depender del
    | plan obligaría a matizar la promesa en cada tarjeta.
    |
    */
    'trial' => [
        'days' => (int) env('BMOS_TRIAL_DAYS', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registro
    |--------------------------------------------------------------------------
    |
    | Plan con el que entra todo el que se registra. El alta no pregunta cuál: comparar precios en
    | mitad de un formulario es pedir una decisión que el cliente aún no puede tomar, y es donde más
    | gente abandona. Entra por el más sencillo y lo cambia desde su panel cuando entienda qué
    | necesita.
    |
    | Si el plan indicado no existe o está inactivo, el registro cae en el plan activo más barato:
    | un catálogo mal configurado no puede dejar sin alta a un cliente.
    |
    */
    'registration' => [
        'default_plan_slug' => env('BMOS_REGISTRATION_PLAN_SLUG', 'basico'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retención de los registros que crecen solos
    |--------------------------------------------------------------------------
    |
    | Al medir la base de datos, `audits` y `system_events` salieron las dos tablas más grandes con el
    | sistema prácticamente vacío. No crecen con las ventas: crecen con cada cambio de cualquier
    | modelo auditado y con cada acción registrada. Las poda `registros:purgar`.
    |
    | Las dos no valen lo mismo, y por eso no traen el mismo valor de fábrica:
    |
    | - `sucesos` es NUESTRO diario de a bordo —que si el cron corrió, que si un aviso falló—. Pasados
    |   tres meses no le sirve a nadie, así que se poda solo.
    |
    | - `auditoria` es el rastro del NEGOCIO: quién cambió este precio, quién borró aquel cliente. Eso
    |   se consulta cuando hay una discusión, a veces meses después. Viene en CERO —o sea, apagado—
    |   porque borrarlo es una decisión del dueño y no nuestra.
    |
    | Cero no borra nada. Un año son 365.
    |
    */
    'retencion' => [
        'sucesos' => (int) env('BMOS_RETENCION_SUCESOS', 90),
        'auditoria' => (int) env('BMOS_RETENCION_AUDITORIA', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Descuentos
    |--------------------------------------------------------------------------
    |
    | Cuánto puede rebajar quien NO tiene el permiso `sales.discount` —el cajero, típicamente—.
    | Quien lo tiene rebaja sin límite.
    |
    | Diez por ciento de fábrica: cubre la rebaja de mostrador de toda la vida —quitar los pesos
    | sueltos para redondear— sin dejar que se regale un artículo. Antes no había ni permiso ni
    | tope: cualquiera podía aplicar un 100 % y el servidor lo aceptaba.
    |
    | Es el valor POR OMISIÓN: cada empresa puede fijar el suyo en sus ajustes, porque una
    | ferretería y una cafetería no tienen el mismo margen.
    |
    */
    'descuentos' => [
        'tope_por_ciento' => (string) env('BMOS_DESCUENTO_TOPE', '10'),
    ],
];
