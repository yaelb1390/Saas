<?php

declare(strict_types=1);

/*
 * Proveedores certificados (PSFE) que una empresa puede conectar desde Facturación electrónica →
 * «Proveedores autorizados». Puede conectar VARIOS en orden: el primero es el principal y los demás,
 * respaldos que se usan si el anterior falla (ver PsfeProvider).
 *
 * Cada conector es `slug => clase` (implementa PsfeDriver). Añadir un proveedor es escribir su
 * conector A PARTIR DE SU DOCUMENTACIÓN y una cuenta de pruebas suya, y añadirlo aquí; nunca se
 * inventa la API de nadie.
 *
 * El simulado («sandbox») no habla con nadie: sirve para recorrer el circuito completo en pruebas y
 * se niega a trabajar en producción, igual que el proveedor de prueba.
 */

use App\Modules\ElectronicInvoicing\Providers\Psfe\Digifact\DigifactPsfeDriver;
use App\Modules\ElectronicInvoicing\Providers\Psfe\SandboxPsfeDriver;

return [
    'drivers' => [
        'digifact' => DigifactPsfeDriver::class,
        'sandbox' => SandboxPsfeDriver::class,
    ],

    // Tras un fallo de comunicación, el proveedor se salta este tiempo para que cada factura no
    // espere su tiempo agotado; mientras, salen por el respaldo.
    'pause_minutes' => (int) env('ECF_PSFE_PAUSE_MINUTES', 5),

    /*
     * Digifact — https://documentacion.digifact.com/do/api.md (API V1.0.4) y
     * https://documentacion.digifact.com/do/nuc/json.md (NUC JSON V1.0.7), consultadas el 2026-10-05.
     * Su API tiene DOS entornos (pruebas y producción): los ambientes de pruebas y certificación de
     * BMIA van al de pruebas. Que ese entorno atienda las dos etapas de la DGII está pendiente de
     * confirmar con Digifact (ver ecf.pending_verification).
     */
    'digifact' => [
        'hosts' => [
            'pruebas' => env('DIGIFACT_TEST_URL', 'https://testnucdo.digifact.com/api'),
            'produccion' => env('DIGIFACT_PROD_URL', 'https://nucdo.digifact.com/api'),
        ],
        // El token dura 30 días [API «Obtención del TOKEN»]; se renueva un día antes.
        'token_refresh_margin_hours' => 24,
        // `BranchInfo.Name` (B061, obligatorio, 1–20). Sus ejemplos usan «1»: la sucursal principal.
        'branch' => env('DIGIFACT_BRANCH', '1'),
    ],
];
