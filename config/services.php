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

    // GET /health (docs/ops.md): a monitor's token for the full report; unset, only ok / warn / fail is said.
    'health' => [
        'token' => env('MYCS_HEALTH_TOKEN'),
    ],

    // The signed license tokens of /licensing/v1/check (docs/licensing.md): the ed25519 secret key, base64, and its id.
    // Unset: no tokens, everything else as before. `php artisan mycs:license-token-key` makes a pair.
    'license_token' => [
        'secret' => env('MYCS_LICENSE_TOKEN_KEY'),
        'kid' => env('MYCS_LICENSE_TOKEN_KID', 'k1'),
        'max_days' => 30,
    ],

    // The WHMCS importer's read of its logins' 2FA (WHMCS 8 keeps it encrypted with this key, from WHMCS's
    // configuration.php): set it for the import run only, then remove it.
    'whmcs' => [
        'cc_encryption_hash' => env('WHMCS_CC_ENCRYPTION_HASH'),
    ],
];
