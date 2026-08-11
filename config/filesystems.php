<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Disco de las fotos de producto
    |--------------------------------------------------------------------------
    |
    | En desarrollo es el disco del propio servidor, que es lo más simple.
    |
    | En producción NO puede serlo: el entorno serverless monta el código en solo lectura y el
    | único directorio escribible, /tmp, se vacía en cada arranque en frío. Guardar ahí las fotos
    | sería peor que no guardarlas: parecerían subidas y desaparecerían solas al rato.
    |
    | Por eso allí se pone PRODUCT_IMAGE_DISK=s3, apuntando a Supabase Storage. La decisión vive
    | aquí y no repartida por el código: quien sube, quien sirve y quien recuadra las fotos
    | preguntan todos por este mismo valor.
    |
    */

    'product_images' => env('PRODUCT_IMAGE_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Supabase Storage y Cloudflare R2 hablan el mismo protocolo que S3, así que este disco
         * sirve para los tres. Se elige con las variables de entorno, sin tocar código:
         * AWS_ENDPOINT apunta al proveedor y AWS_USE_PATH_STYLE_ENDPOINT=true es obligatorio en
         * ambos (no admiten el estilo de subdominio de Amazon).
         */
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Cloudflare R2 (S3-compatible). Se usa como destino externo de los respaldos. Mientras las
        // credenciales R2_* estén vacías, el respaldo se guarda solo en local; al rellenarlas, el
        // backup empieza a subir automáticamente fuera del servidor.
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => env('R2_REGION', 'auto'),
            'bucket' => env('R2_BUCKET'),
            'endpoint' => env('R2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
