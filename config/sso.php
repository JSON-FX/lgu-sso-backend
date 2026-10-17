<?php

return [

    // Optional application-counter store; login and registration keep their existing limiter.
    'app_rate_limit_store' => env('SSO_APP_RATE_LIMIT_STORE'),

    'capacity_store' => env('SSO_CAPACITY_STORE', 'database'),

    'batch' => [
        'enabled' => (bool) env('SSO_BATCH_ENABLED', false),
        'application_uuid' => env('SSO_BATCH_APPLICATION_UUID', ''),
        // Zero budgets prevent activation until isolated measurement and release review.
        'items_per_minute' => (int) env('SSO_BATCH_ITEMS_PER_MINUTE', 0),
        'global_items_per_minute' => (int) env('SSO_BATCH_GLOBAL_ITEMS_PER_MINUTE', 0),
    ],

    'client_comparison' => [
        'enabled' => (bool) env('SSO_CLIENT_COMPARISON_ENABLED', false),
        'application_uuid' => env('SSO_CLIENT_COMPARISON_APPLICATION_UUID', ''),
        'ttl' => 60,
        'cold_per_minute' => (int) env('SSO_CLIENT_COLD_PER_MINUTE', 0),
        'global_cold_per_minute' => (int) env('SSO_CLIENT_GLOBAL_COLD_PER_MINUTE', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | SSO Cookie Name
    |--------------------------------------------------------------------------
    |
    | The name of the cookie used to share the JWT token across subdomains.
    |
    */

    'cookie_name' => env('SSO_COOKIE_NAME', 'lgu_sso_token'),

    /*
    |--------------------------------------------------------------------------
    | SSO Cookie Domain
    |--------------------------------------------------------------------------
    |
    | Central cookies are host-only. Consumers use authorization codes,
    | never a shared central bearer cookie.
    |
    */

    'cookie_domain' => null,

    /*
    |--------------------------------------------------------------------------
    | SSO Cookie Secure
    |--------------------------------------------------------------------------
    |
    | Whether the cookie should only be sent over HTTPS.
    | Set to true in production.
    |
    */

    'cookie_secure' => env('SSO_COOKIE_SECURE', false),

    /*
    |--------------------------------------------------------------------------
    | SSO Cookie SameSite
    |--------------------------------------------------------------------------
    |
    | The SameSite attribute for the SSO cookie.
    | "lax" prevents CSRF while allowing top-level navigation.
    |
    */

    'cookie_same_site' => env('SSO_COOKIE_SAME_SITE', 'lax'),

    /*
    |--------------------------------------------------------------------------
    | SSO Cookie Lifetime
    |--------------------------------------------------------------------------
    |
    | The cookie lifetime in minutes. Should match the JWT TTL.
    |
    */

    'cookie_lifetime' => (int) env('SSO_COOKIE_LIFETIME', 1440),

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of allowed origins for CORS when credentials
    | are included. Cannot use "*" with credentials.
    |
    */

    'allowed_origins' => env('SSO_ALLOWED_ORIGINS', ''),

];
