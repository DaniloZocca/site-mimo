<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
        'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
    ],

    'shipping' => [
        'origin_cep' => env('SHIPPING_ORIGIN_CEP', '01001000'), // Praça da Sé, São Paulo - SP
        'melhor_envio' => [
            'url' => env('MELHOR_ENVIO_URL', 'https://sandbox.melhorenvio.com.br'),
            'token' => env('MELHOR_ENVIO_TOKEN', ''),
        ],
        'packaging' => [
            'min_height_cm' => 6,
            'min_width_cm' => 11,
            'min_length_cm' => 16,
            'tare_weight_grams' => 50,
            'bubble_wrap_grams' => 30,
        ],
    ],

];
