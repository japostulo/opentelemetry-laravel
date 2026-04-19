<?php

namespace Haoc\OpenTelemetry;

/**
 * Resolves the active profile + overrides + env vars into a single object
 * consumed by the service provider and the TraceRequest middleware.
 *
 * Precedence: explicit config('haoc-otel.<key>') > env (HAOC_OTEL_*) >
 * profile defaults.
 */
class Profile
{
    public const MINIMAL  = 'minimal';
    public const STANDARD = 'standard';
    public const VERBOSE  = 'verbose';

    /** @var array<string, mixed> */
    public readonly array $data;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Resolves the profile from the `haoc-otel` Laravel config (which itself
     * already reads env vars via env()).
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $name = $config['profile'] ?? self::MINIMAL;
        $base = self::baselineFor($name);

        // Sample ratio: explicit config > base default; in production with
        // no override, drop to 0.2 if base was 1.0.
        $explicitRatio = $config['sample_ratio'];
        if ($explicitRatio === '' || $explicitRatio === null) {
            $ratio = $base['sample_ratio'];
            $env = $config['environment'] ?? 'local';
            if (
                $name !== self::VERBOSE
                && in_array($env, ['production', 'prod'], true)
                && $ratio === 1.0
            ) {
                $ratio = 0.2;
            }
        } else {
            $ratio = max(0.0, min(1.0, (float) $explicitRatio));
        }

        $captureRequest = self::resolveBool(
            $config['capture_request_body'] ?? null,
            $base['capture_request_body'],
        );
        $captureResponse = self::resolveBool(
            $config['capture_response_body'] ?? null,
            $base['capture_response_body'],
        );

        $ignoreRoutes = array_merge(
            $base['ignore_routes'],
            self::compilePatterns($config['ignore_routes'] ?? []),
        );

        return new self([
            'profile'               => $name,
            'sample_ratio'          => $ratio,
            'capture_request_body'  => $captureRequest,
            'capture_response_body' => $captureResponse,
            'ignore_routes'         => $ignoreRoutes,
            'log_destination'       => $config['log_destination'] ?? 'both',
        ]);
    }

    /**
     * Compiles a list of pattern strings (or regex literals) into delimited
     * regex strings ready for preg_match.
     *
     * @param array<int, string> $patterns
     * @return array<int, string>
     */
    private static function compilePatterns(array $patterns): array
    {
        $out = [];
        foreach ($patterns as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            $out[] = '/' . str_replace('/', '\/', $p) . '/i';
        }
        return $out;
    }

    private static function resolveBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        $v = strtolower((string) $value);
        if (in_array($v, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($v, ['false', '0', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    /**
     * @return array{
     *     sample_ratio: float,
     *     capture_request_body: bool,
     *     capture_response_body: bool,
     *     ignore_routes: array<int, string>,
     * }
     */
    private static function baselineFor(string $name): array
    {
        $defaultIgnore = [
            '/^health$/i',
            '/^healthz$/i',
            '/^up$/i',
            '/^_debugbar/i',
            '/^telescope/i',
            '/^horizon/i',
        ];

        return match ($name) {
            self::STANDARD => [
                'sample_ratio'          => 1.0,
                'capture_request_body'  => true,
                'capture_response_body' => true,
                'ignore_routes'         => $defaultIgnore,
            ],
            self::VERBOSE => [
                'sample_ratio'          => 1.0,
                'capture_request_body'  => true,
                'capture_response_body' => true,
                'ignore_routes'         => [],
            ],
            default => [
                'sample_ratio'          => 1.0,
                'capture_request_body'  => false,
                'capture_response_body' => false,
                'ignore_routes'         => $defaultIgnore,
            ],
        };
    }

    /**
     * Returns true if any of the compiled regex patterns matches the value.
     *
     * @param array<int, string> $patterns
     */
    public static function matchesAny(array $patterns, string $value): bool
    {
        foreach ($patterns as $p) {
            if (@preg_match($p, $value) === 1) {
                return true;
            }
        }
        return false;
    }
}
