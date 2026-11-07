<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
     | Google Cloud TTS: env adlari eski monolitle ayni. Anahtar yoksa istemci cihaz sesine duser.
     | Chirp3-HD pitch desteklemez, proje veya bolgede kapaliysa fallback sese duser.
     */
    'google_tts' => [
        'key'            => env('GOOGLE_TTS_API_KEY', ''),
        'voice'          => env('GOOGLE_TTS_VOICE', 'tr-TR-Chirp3-HD-Charon'),
        'voice_fallback' => env('GOOGLE_TTS_VOICE_FALLBACK', 'tr-TR-Wavenet-E'),
        'rate'           => (float) env('GOOGLE_TTS_RATE', 0.95),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
