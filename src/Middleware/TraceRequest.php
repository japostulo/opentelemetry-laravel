<?php

namespace Haoc\OpenTelemetry\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\Component\HttpFoundation\Response;

class TraceRequest
{
    public function __construct(private TracerInterface $tracer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->uri() ?? $request->path();
        $method = $request->method();
        $spanName = "{$method} /{$route}";

        $sensitiveFields = config('haoc-otel.sensitive_fields', []);

        $span = $this->tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $scope = $span->activate();

        $span->setAttribute('http.method', $method);
        $span->setAttribute('http.route', "/{$route}");
        $span->setAttribute('http.url', $request->fullUrl());
        $span->setAttribute('http.target', $request->getRequestUri());
        $span->setAttribute('environment', config('haoc-otel.environment'));

        // ── User Identity ───────────────────────────────────────────────
        $user = $request->user();
        if ($user) {
            $span->setAttribute('haoc.user.id', (string) $user->getAuthIdentifier());
            if (method_exists($user, 'getEmail')) {
                $span->setAttribute('haoc.user.email', $user->getEmail());
            } elseif (isset($user->email)) {
                $span->setAttribute('haoc.user.email', $user->email);
            }
        }

        // ── Infrastructure / Hop Tracking ───────────────────────────────
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor) {
            $span->setAttribute('http.x_forwarded_for', $forwardedFor);
            $hops = array_map('trim', explode(',', $forwardedFor));
            $span->setAttribute('network.hop_count', count($hops));
            $span->setAttribute('http.client_ip', $hops[0]);
        }

        $realIp = $request->header('X-Real-IP');
        if ($realIp) {
            $span->setAttribute('http.x_real_ip', $realIp);
        }

        $forwardedHost = $request->header('X-Forwarded-Host');
        if ($forwardedHost) {
            $span->setAttribute('http.x_forwarded_host', $forwardedHost);
        }

        $forwardedProto = $request->header('X-Forwarded-Proto');
        if ($forwardedProto) {
            $span->setAttribute('http.x_forwarded_proto', $forwardedProto);
        }

        $via = $request->header('Via');
        if ($via) {
            $span->setAttribute('http.via', $via);
        }

        // ── Baggage from Frontend ───────────────────────────────────────
        $baggageHeader = $request->header('baggage');
        if ($baggageHeader) {
            foreach (explode(',', $baggageHeader) as $entry) {
                $parts = explode('=', trim($entry), 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = urldecode(trim($parts[1]));
                    if (preg_match('/^(haoc\.|page\.|browser\.|device\.|app\.)/', $key)) {
                        $span->setAttribute($key, $value);
                    }
                }
            }
        }

        // Query params
        foreach ($this->sanitize($request->query(), $sensitiveFields) as $key => $value) {
            $span->setAttribute("query.{$key}", is_string($value) ? $value : json_encode($value));
        }

        // Route params
        foreach ($this->sanitize($request->route()?->parameters() ?? [], $sensitiveFields) as $key => $value) {
            $span->setAttribute("params.{$key}", is_string($value) ? $value : json_encode($value));
        }

        // Body (POST/PUT/PATCH)
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && $request->isJson()) {
            foreach ($this->flattenAttributes('body', $this->sanitize($request->all(), $sensitiveFields)) as $key => $value) {
                $span->setAttribute($key, $value);
            }
        }

        $traceId = $span->getContext()->getTraceId();

        Log::info("{$method} /{$route} [{$traceId}]", [
            'http.method' => $method,
            'http.route' => "/{$route}",
            'query' => $this->sanitize($request->query(), $sensitiveFields),
            'params' => $this->sanitize($request->route()?->parameters() ?? [], $sensitiveFields),
        ]);

        $startTime = microtime(true);

        try {
            /** @var Response $response */
            $response = $next($request);

            $duration = round((microtime(true) - $startTime) * 1000);
            $statusCode = $response->getStatusCode();

            $span->setAttribute('http.status_code', $statusCode);
            $span->setAttribute('http.duration_ms', $duration);
            $response->headers->set('X-Trace-Id', $traceId);

            if ($statusCode >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR, "HTTP {$statusCode}");
            }

            Log::info("{$method} /{$route} {$statusCode} {$duration}ms [{$traceId}]", [
                'http.method' => $method,
                'http.route' => "/{$route}",
                'http.status_code' => $statusCode,
                'http.duration_ms' => $duration,
            ]);

            return $response;
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

            $span->setAttribute('http.status_code', $statusCode);
            $span->setAttribute('http.duration_ms', $duration);
            $span->setAttribute('error.message', $e->getMessage());
            $span->setAttribute('error.type', get_class($e));
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);

            Log::error("{$method} /{$route} {$statusCode} {$duration}ms [{$traceId}] {$e->getMessage()}", [
                'http.method' => $method,
                'http.route' => "/{$route}",
                'http.status_code' => $statusCode,
                'http.duration_ms' => $duration,
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                ],
            ]);

            throw $e;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    private function sanitize(array $data, array $sensitiveFields): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitiveFields, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value, $sensitiveFields);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    private function flattenAttributes(string $prefix, array $data, int $depth = 0): array
    {
        if ($depth > 3) {
            return [];
        }

        $result = [];
        foreach ($data as $key => $value) {
            $attrKey = "{$prefix}.{$key}";
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenAttributes($attrKey, $value, $depth + 1));
            } elseif (is_scalar($value)) {
                $result[$attrKey] = (string) $value;
            }
        }
        return $result;
    }
}
