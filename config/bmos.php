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
    | Ya no hay `plan_slug`: antes fijaba el plan de toda prueba porque el cliente elegía módulos
    | sueltos y hacía falta uno de referencia del que heredarlos. Ahora el plan lo elige él.
    |
    */
    'trial' => [
        'days' => (int) env('BMOS_TRIAL_DAYS', 15),
    ],
];
