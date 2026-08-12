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
];
