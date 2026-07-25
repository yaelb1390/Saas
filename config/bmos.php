<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Prueba gratuita (registro self-service)
    |--------------------------------------------------------------------------
    |
    | Un cliente puede registrarse solo para una prueba. `days` es la duración; el plan `plan_slug`
    | solo aporta los módulos que se heredan si el cliente no fija su propia selección (el acceso real
    | lo gobierna company.modules). Debe ser un plan con todos los módulos (modules = null).
    |
    */
    'trial' => [
        'days' => (int) env('BMOS_TRIAL_DAYS', 15),
        'plan_slug' => env('BMOS_TRIAL_PLAN_SLUG', 'empresarial'),
    ],
];
