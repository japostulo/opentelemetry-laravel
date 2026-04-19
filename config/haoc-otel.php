<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Service Name
    |--------------------------------------------------------------------------
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
    */
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://host.docker.internal:4318'),

    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    | Named noise-reduction baseline:
    |   - `minimal` (default): only the request span + DB queries + errors;
    |     health/horizon/telescope routes ignored; body/response capture OFF.
    |   - `standard`: minimal + body/response capture.
    |   - `verbose`: legacy "everything on" behaviour.
    |
    */
    'profile' => env('HAOC_OTEL_PROFILE', 'minimal'),

    /*
    |--------------------------------------------------------------------------
    | Sample Ratio
    |--------------------------------------------------------------------------
    | Head-based sampler ratio for ParentBased(TraceIdRatioBased). 0..1.
    | Defaults: 1.0 in dev/local, 0.2 in production (resolved at runtime).
    */
    'sample_ratio' => env('HAOC_OTEL_SAMPLE_RATIO'),

    /*
    |--------------------------------------------------------------------------
    | Ignored Routes
    |--------------------------------------------------------------------------
    | Route patterns (case-insensitive regex) for which the TraceRequest
    | middleware short-circuits — no span is created and no log is emitted.
    | Merged with the active profile defaults.
    */
    'ignore_routes' => array_filter(explode(',', (string) env('HAOC_OTEL_IGNORE_ROUTES', ''))),

    /*
    |--------------------------------------------------------------------------
    | Capture toggles
    |--------------------------------------------------------------------------
    | Whether to flatten request/response bodies into span attributes.
    | Both default to FALSE in `minimal`; TRUE in `standard`/`verbose`.
    */
    'capture_request_body' => env('HAOC_OTEL_CAPTURE_BODY'),
    'capture_response_body' => env('HAOC_OTEL_CAPTURE_RESPONSE'),

    /*
    |--------------------------------------------------------------------------
    | Log Destination
    |--------------------------------------------------------------------------
    | Where Laravel logs piped through OtelHandler are routed:
    |   - `signoz`: emit via OTLP only.
    |   - `console`: do not emit via OTLP (handler becomes a no-op).
    |   - `both` (default): emit via OTLP; the application can still attach
    |     its own console/file handlers in the logging stack.
    |   - `none`: handler becomes a no-op.
    */
    'log_destination' => env('LOG_DESTINATION', 'both'),

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
