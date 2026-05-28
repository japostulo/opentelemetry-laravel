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
    |   - `standard`: minimal + sanitized payload in structured logs.
    |   - `verbose`: legacy "everything on" behaviour.
    |
    */
    'profile' => env('OTEL_PROFILE', 'minimal'),

    /*
    |--------------------------------------------------------------------------
    | Sample Ratio
    |--------------------------------------------------------------------------
    | Head-based sampler ratio for ParentBased(TraceIdRatioBased). 0..1.
    | Defaults: 1.0 in dev/local, 0.2 in production (resolved at runtime).
    */
    'sample_ratio' => env('OTEL_SAMPLE_RATIO'),

    /*
    |--------------------------------------------------------------------------
    | Ignored Routes
    |--------------------------------------------------------------------------
    | Route patterns (case-insensitive regex) for which the TraceRequest
    | middleware short-circuits — no span is created and no log is emitted.
    | Merged with the active profile defaults.
    */
    'ignore_routes' => array_filter(explode(',', (string) env('OTEL_IGNORE_ROUTES', ''))),

    /*
    |--------------------------------------------------------------------------
    | Capture toggles
    |--------------------------------------------------------------------------
    | Whether to flatten request/response bodies into span attributes.
    | Defaults are FALSE in `minimal` and `standard`; TRUE only in `verbose`.
    */
    'capture_request_body' => env('OTEL_CAPTURE_BODY'),
    'capture_response_body' => env('OTEL_CAPTURE_RESPONSE'),

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
        // PT-BR / HAOC PII
        'cpf', 'rg', 'cnpj', 'cartao_sus', 'cns',
    ],

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    | user.* is the canonical cross-plugin identity contract. Email is opt-in.
    */
    'identity' => [
        'propagate' => env('HAOC_OTEL_PROPAGATE_USER', true),
        'user_id_mode' => env('HAOC_OTEL_USER_ID_MODE', 'raw'), // raw | hash | off
        'include_email' => env('HAOC_OTEL_INCLUDE_USER_EMAIL', false),
        'hash_salt' => env('HAOC_OTEL_HASH_SALT', ''),
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy / LGPD
    |--------------------------------------------------------------------------
    */
    'privacy' => [
        'public_ip_mode' => env('HAOC_OTEL_PUBLIC_IP_MODE', 'raw'), // raw | hash | off
        'hash_salt' => env('HAOC_OTEL_HASH_SALT', ''),
        'redact_value_patterns' => [],
    ],
];
