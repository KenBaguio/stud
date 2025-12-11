<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    // Add Google OAuth config
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Support both GOOGLE_CALLBACK_REDIRECTS and GOOGLE_REDIRECT for compatibility
        'redirect' => env('GOOGLE_CALLBACK_REDIRECTS') ?: env('GOOGLE_REDIRECT'),
    ],
    
    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'base_url' => env('PAYMONGO_API_BASE', 'https://api.paymongo.com/v1'),
        'return_url' => env('PAYMONGO_RETURN_URL'),
    ],

];
