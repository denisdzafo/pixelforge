<?php

// Only the options that differ from the framework's config/app.php; Laravel merges this file over its own
return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Proxies / load balancers allowed to set X-Forwarded-* headers (client IP,
    | https). Comma-separated IPs or CIDR ranges, "*" for any (only when the app
    | can't be reached except through the proxy). Empty = none: X-Forwarded-*
    | headers are ignored.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES') === '*'
        ? '*'
        : array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

];
