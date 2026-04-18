<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Service Name
    |--------------------------------------------------------------------------
    |
    | The name that will appear in SigNoz / your observability backend.
    |
    */
    'service_name' => env('OTEL_SERVICE_NAME', env('APP_NAME', 'laravel')),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    */
    'environment' => env('OTEL_ENVIRONMENT', env('APP_ENV', 'local')),

    /*
    |--------------------------------------------------------------------------
    | OTLP Endpoint
    |--------------------------------------------------------------------------
    |
    | When running inside Docker the default points to the host machine so
    | the collector is reachable without altering the container network.
    | Override with OTEL_EXPORTER_OTLP_ENDPOINT if needed.
    |
    */
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://host.docker.internal:4318'),

    /*
    |--------------------------------------------------------------------------
    | Sensitive fields (redacted in span attributes)
    |--------------------------------------------------------------------------
    */
    'sensitive_fields' => [
        'password', 'senha', 'secret', 'token', 'access_token',
        'refresh_token', 'authorization', 'db_password', 'tasy_password',
    ],
];
