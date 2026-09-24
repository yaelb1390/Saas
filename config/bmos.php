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
        // Los errores agrupados, por la última vez que ocurrieron. Antes no se podaban nunca. Igual que
        // los sucesos, son NUESTRO diario de a bordo: pasados tres meses sin repetirse no le sirven a nadie.
        'errores' => (int) env('BMOS_RETENCION_ERRORES', 90),
        // El histórico de comprobaciones de salud (Fase 3). Corto a propósito: es para ver una
        // tendencia de los últimos días, no un archivo; `health_checks` («cómo está ahora») no se poda.
        'salud' => (int) env('BMOS_RETENCION_SALUD', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoreo de la plataforma
    |--------------------------------------------------------------------------
    |
    | Lo que se puede ajustar del monitoreo sin tocar código. Cada fase del monitoreo añade aquí su
    | sección; el porqué de cada cosa está en docs/MONITOREO.md.
    |
    */
    'monitoreo' => [
        'errores' => [
            /*
             * A qué servicio pertenece un error según el host al que se llamó.
             *
             * Sirve para agrupar y filtrar («¿falla Polar o falla la IA?»), y entra en la huella del error:
             * el mismo «Connection timed out» contra dos servicios distintos son dos problemas. El host se
             * compara entero o como sufijo (`sandbox-api.polar.sh` coincide con `polar.sh`). El de Evolution
             * no está aquí porque es de cada instalación: sale de `evolution.base_url`.
             */
            'hosts' => [
                'polar.sh' => 'polar',
                'api.zernio.com' => 'zernio',
                'api.openai.com' => 'ai',
                'generativelanguage.googleapis.com' => 'ai',
                'api.anthropic.com' => 'ai',
            ],
        ],

        /*
         * Fase 2: cuándo un grupo de errores basta para abrir un incidente por sí solo, sin que
         * nadie lo abra a mano. Dos disparadores, cada uno con su ventana: una RACHA (muchas veces
         * en poco rato, sea la empresa que sea) o que el mismo fallo alcance a varias empresas a la
         * vez (una sola vez en cada una puede no ser mucho, pero repartido entre varias sí lo es).
         */
        'incidentes' => [
            'racha_umbral' => (int) env('BMOS_INCIDENTES_RACHA_UMBRAL', 25),
            'racha_minutos' => (int) env('BMOS_INCIDENTES_RACHA_MINUTOS', 15),
            'empresas_umbral' => (int) env('BMOS_INCIDENTES_EMPRESAS_UMBRAL', 3),
            'empresas_minutos' => (int) env('BMOS_INCIDENTES_EMPRESAS_MINUTOS', 30),
            // Fase 3: comprobaciones de salud SEGUIDAS y sin responder antes de abrir un incidente.
            // Dos y no una: una sonda puede fallar suelta por una red que titubea un segundo, y eso
            // no es un servicio caído.
            'salud_fallos_umbral' => (int) env('BMOS_INCIDENTES_SALUD_FALLOS_UMBRAL', 2),
        ],
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
