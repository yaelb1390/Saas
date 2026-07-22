<?php

/*
 * Configuración de IA. El proveedor por defecto se toma de AI_DEFAULT_PROVIDER; si el proveedor
 * elegido no tiene su API key, el sistema cae al proveedor local (determinista, sin red).
 */
return [
    'default' => env('AI_DEFAULT_PROVIDER', 'local'),

    'embedding_dimensions' => 128,

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
            'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'chat_model' => env('ANTHROPIC_CHAT_MODEL', 'claude-sonnet-5'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        ],
    ],
];
