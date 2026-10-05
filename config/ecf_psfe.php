<?php

declare(strict_types=1);

/*
 * Catálogo de proveedores certificados (PSFE) que una empresa puede conectar desde
 * Facturación electrónica → «Conecta tu proveedor autorizado».
 *
 * Cada entrada es `slug => clase del conector` (implementa PsfeDriver). Añadir un proveedor es
 * escribir su conector A PARTIR DE SU DOCUMENTACIÓN y una cuenta de pruebas suya, y añadirlo aquí;
 * nunca se inventa la API de nadie.
 *
 * El simulado («sandbox») no habla con nadie: sirve para recorrer el circuito completo en pruebas y
 * se niega a trabajar en producción, igual que el proveedor de prueba.
 */

use App\Modules\ElectronicInvoicing\Providers\Psfe\SandboxPsfeDriver;

return [
    'drivers' => [
        'sandbox' => SandboxPsfeDriver::class,
    ],
];
