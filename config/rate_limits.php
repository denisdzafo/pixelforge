<?php

/**
 * Request limits per minute (AppServiceProvider defines the limiters, routes use them with `throttle:<name>`).
 * Over the limit -> 429 with a plain message the frontend shows as-is.
 */
return [

    // Every endpoint behind jwtAuth, per user
    'api_per_minute' => (int) env('RATE_LIMIT_API_PER_MINUTE', 240),

    // Register, login and refresh (no access token yet), per IP
    'auth_per_minute' => (int) env('RATE_LIMIT_AUTH_PER_MINUTE', 30),

];
