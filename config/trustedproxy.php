<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated IP addresses or CIDR ranges of reverse proxies whose
    | X-Forwarded-* headers are trusted (read by Laravel's TrustProxies
    | middleware). Leave empty when the app is not behind a proxy. Never
    | trust a range that untrusted clients can connect from.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
