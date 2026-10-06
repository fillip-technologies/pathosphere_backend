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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'abdm' => [
        'callback_secret' => env('ABDM_CALLBACK_SECRET', ''),
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID', ''),
        'key_secret' => env('RAZORPAY_KEY_SECRET', ''),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET', ''),
    ],

    // Delivery receipts from the SMS / WhatsApp vendors (spec §9 rule 5).
    'messaging' => [
        'webhook_secret' => env('MESSAGING_WEBHOOK_SECRET', ''),
    ],

    // E-sign vendor webhooks (spec §5.1 step 4), our signed JSON format until a vendor is chosen.
    'esign' => [
        'webhook_secret' => env('ESIGN_WEBHOOK_SECRET', ''),
    ],

    // Report PDFs (spec §3): `chromium` on the VPS, `fake` locally and in tests.
    'pdf' => [
        'renderer' => env('PDF_RENDERER', 'fake'),
        'chromium_binary' => env('CHROMIUM_BINARY', '/usr/bin/chromium'),
    ],

];
