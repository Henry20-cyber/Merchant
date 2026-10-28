<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Receipt Verification Signing Key
    |--------------------------------------------------------------------------
    |
    | Production must use a dedicated receipt verification key.
    | APP_KEY is permitted as a fallback only in the testing environment.
    |
    */

    'key' => env(
        'RECEIPT_VERIFICATION_KEY',
        env('APP_ENV') === 'testing' ? env('APP_KEY') : null
    ),
];
