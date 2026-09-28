<?php

return [

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
