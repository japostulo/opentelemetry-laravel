<?php

namespace Haoc\OpenTelemetry\Middleware;

use Closure;
use Haoc\OpenTelemetry\Attributes\SemanticAttributes;
use Haoc\OpenTelemetry\Profile;
use Haoc\OpenTelemetry\Sanitizer\PayloadSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use Symfony\Component\HttpFoundation\Response;

class TraceRequest
{
    public function __construct(
        private TracerInterface $tracer,
        private Profile $profile,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->uri() ?? $request->path();
        $method = $request->method();

        // ── Short-circuit: ignored routes pass through untraced ────────
        $ignorePatterns = $this->profile->get('ignore_routes', []);
        if (Profile::matchesAny($ignorePatterns, $route)) {
            return $next($request);
        }

        $captureBody      = (bool) $this->profile->get('capture_request_body', false);
        $captureResponse  = (bool) $this->profile->get('capture_response_body', false);
        $logPayloadMode   = (string) $this->profile->get('log_payload_mode', 'none');
        $isPreflight      = ($method === 'OPTIONS');

        // verbose logs OPTIONS, other profiles do not
        $profileName = (string) $this->profile->get('profile', 'minimal');
        $shouldLogPreflight = ($profileName === Profile::VERBOSE);

        $spanName = "{$method} /{$route}";

        // Custom fields from config are ADDITIVE — defaults always apply.
        // Mirrors Node's mergeSensitiveFields() behaviour.
        $extraFields     = config('otel.sensitive_fields', []);
        $sensitiveFields = array_values(array_unique(array_merge(
            PayloadSanitizer::DEFAULT_SENSITIVE_FIELDS,
            is_array($extraFields) ? $extraFields : [],
        )));

        // Extract W3C trace context + baggage from incoming request so this
        // span is correctly parented within the distributed trace.
        $propagator = new MultiTextMapPropagator([
            TraceContextPropagator::getInstance(),
            BaggagePropagator::getInstance(),
        ]);
        $parentContext = $propagator->extract($request->headers->all());

        $span = $this->tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($parentContext)
            ->startSpan();

        $scope = $span->activate();

        // New OTel semconv attributes
        $span->setAttribute(SemanticAttributes::HTTP_REQUEST_METHOD, $method);
        $span->setAttribute(SemanticAttributes::HTTP_ROUTE, "/{$route}");
        $span->setAttribute(SemanticAttributes::URL_PATH, $request->getPathInfo());
        $span->setAttribute(SemanticAttributes::USER_AGENT_ORIGINAL, (string) $request->userAgent());
        $span->setAttribute(SemanticAttributes::OTEL_PROFILE, $profileName);
        $span->setAttribute('environment', config('otel.environment'));
        // Legacy aliases (kept for backward compat with ClickHouse queries)
        $span->setAttribute(SemanticAttributes::HTTP_METHOD_LEGACY, $method);
        $span->setAttribute('http.url', $request->fullUrl());
        $span->setAttribute('http.target', $request->getRequestUri());
        if ($isPreflight) {
            $span->setAttribute(SemanticAttributes::HTTP_IS_PREFLIGHT, true);
        }

        // ── User Identity ───────────────────────────────────────────────
        $user = $request->user();
        if ($user) {
            $span->setAttribute('user.id', (string) $user->getAuthIdentifier());
            if (method_exists($user, 'getEmail')) {
                $span->setAttribute('user.email', $user->getEmail());
            } elseif (isset($user->email)) {
                $span->setAttribute('user.email', $user->email);
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
                    if (preg_match('/^(page\.|browser\.|device\.|app\.)/', $key)) {
                        $span->setAttribute($key, $value);
                    }
                }
            }
        }

        // ── Test correlation header ─────────────────────────────────────
        $testRunId = $request->header('x-test-run-id');
        if ($testRunId) {
            $span->setAttribute(SemanticAttributes::TEST_RUN_ID, $testRunId);
        }

        // Query params
        foreach ($this->sanitize($request->query(), $sensitiveFields) as $key => $value) {
            $span->setAttribute("request.query.{$key}", is_scalar($value) ? $value : json_encode($value));
        }

        // Route params
        foreach ($this->sanitize($request->route()?->parameters() ?? [], $sensitiveFields) as $key => $value) {
            $span->setAttribute("request.params.{$key}", is_scalar($value) ? $value : json_encode($value));
        }

        // ── Request body: read once for span attrs and log ──────────────
        // Reading is decoupled from $captureBody so that log modes ('json-attr'
        // or 'flatten') can access the body even when span flattening is off.
        $requestBodyRaw = null;
        if (
            in_array($method, ['POST', 'PUT', 'PATCH'])
            && $request->isJson()
            && ($captureBody || $logPayloadMode !== 'none')
        ) {
            $requestBodyRaw = $request->all();
        }

        // "Input payload" = the primary input data:
        // GET/HEAD/DELETE → query params (if any); POST/PUT/PATCH → JSON body
        $inputPayload = null;
        if (in_array($method, ['GET', 'HEAD', 'DELETE'])) {
            $queryData = $request->query();
            $inputPayload = !empty($queryData) ? $queryData : null;
        } else {
            $inputPayload = $requestBodyRaw;
        }

        // Span: flatten body into attributes (verbose profile — captureBody=true)
        $requestBodyAttrs = [];
        if ($captureBody && $inputPayload !== null) {
            $requestBodyAttrs = $this->flattenAttributes(
                'body',
                $this->sanitize($inputPayload, $sensitiveFields)
            );
            foreach ($requestBodyAttrs as $key => $value) {
                $span->setAttribute($key, $value);
            }
        }

        $traceId = $span->getContext()->getTraceId();

        // ── Request log ─ skip for OPTIONS unless verbose ──────────────────────
        if (!$isPreflight || $shouldLogPreflight) {
            $maxReqBytes = (int) $this->profile->get('max_request_body_bytes', 16 * 1024);
            $reqLogCtx = [
                SemanticAttributes::HTTP_REQUEST_METHOD => $method,
                SemanticAttributes::HTTP_ROUTE          => "/{$route}",
                SemanticAttributes::OTEL_PROFILE        => $profileName,
                SemanticAttributes::LOG_EVENT      => $isPreflight
                    ? SemanticAttributes::LOG_EVENT_PREFLIGHT
                    : SemanticAttributes::LOG_EVENT_REQUEST,
                SemanticAttributes::LOG_TITLE      => "{$method} /{$route} [{$traceId}]",
            ];

            if ($logPayloadMode === 'json-attr' && $inputPayload !== null) {
                $json = PayloadSanitizer::sanitizeToJsonAttr($inputPayload, [
                    'sensitiveFields' => $sensitiveFields,
                    'maxBytes'        => $maxReqBytes,
                ]);
                if ($json !== null) {
                    $reqLogCtx[SemanticAttributes::REQUEST_JSON] = $json;
                }
            } elseif ($logPayloadMode === 'flatten' && $inputPayload !== null) {
                foreach ($this->flattenAttributes('body', $this->sanitize($inputPayload, $sensitiveFields)) as $k => $v) {
                    $reqLogCtx[$k] = $v;
                }
            }

            Log::info("{$method} /{$route} [{$traceId}]", $reqLogCtx);
        }

        $startTime = microtime(true);

        try {
            /** @var Response $response */
            $response = $next($request);

            $duration = round((microtime(true) - $startTime) * 1000);
            $statusCode = $response->getStatusCode();

            $span->setAttribute(SemanticAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
            $span->setAttribute('http.duration_ms', $duration);
            $response->headers->set('X-Trace-Id', $traceId);

            if ($statusCode >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR, "HTTP {$statusCode}");
            }

            // ── Response body: read once for span attrs and log ─────────
            $responseBodyRaw = null;
            $responseContentType = $response->headers->get('Content-Type', '');
            if (
                str_contains($responseContentType, 'application/json')
                && ($captureResponse || $logPayloadMode !== 'none')
            ) {
                $rawContent = $response->getContent();
                if ($rawContent !== false && $rawContent !== '') {
                    $decodedBody = json_decode($rawContent, true);
                    if (is_array($decodedBody)) {
                        $responseBodyRaw = $decodedBody;
                    }
                }
            }

            // Span: flatten response body (verbose profile — captureResponse=true)
            $responseBodyAttrs = [];
            if ($captureResponse && $responseBodyRaw !== null) {
                $sanitized = $this->sanitize($responseBodyRaw, $sensitiveFields);
                $responseBodyAttrs = $this->flattenAttributes('response.body', $sanitized);
                foreach ($responseBodyAttrs as $key => $value) {
                    $span->setAttribute($key, $value);
                }
            }

            // ── Response log — skip for OPTIONS unless verbose ──────────
            if (!$isPreflight || $shouldLogPreflight) {
                $maxResBytes = (int) $this->profile->get('max_response_body_bytes', 16 * 1024);
                $logContext = [
                    SemanticAttributes::HTTP_REQUEST_METHOD        => $method,
                    SemanticAttributes::HTTP_ROUTE                 => "/{$route}",
                    SemanticAttributes::HTTP_RESPONSE_STATUS_CODE  => $statusCode,
                    'http.duration_ms'                             => $duration,
                    SemanticAttributes::OTEL_PROFILE               => $profileName,
                    SemanticAttributes::LOG_EVENT             => SemanticAttributes::LOG_EVENT_RESPONSE,
                    SemanticAttributes::LOG_TITLE             => "{$method} /{$route} {$statusCode} {$duration}ms [{$traceId}]",
                ];

                if ($logPayloadMode === 'json-attr' && $responseBodyRaw !== null) {
                    $json = PayloadSanitizer::sanitizeToJsonAttr($responseBodyRaw, [
                        'sensitiveFields' => $sensitiveFields,
                        'maxBytes'        => $maxResBytes,
                    ]);
                    if ($json !== null) {
                        $logContext[SemanticAttributes::RESPONSE_JSON] = $json;
                    }
                } elseif ($logPayloadMode === 'flatten' && $responseBodyRaw !== null) {
                    $sanitized = $this->sanitize($responseBodyRaw, $sensitiveFields);
                    foreach ($this->flattenAttributes('response.body', $sanitized) as $k => $v) {
                        $logContext[$k] = $v;
                    }
                }

                $logMessage = "{$method} /{$route} {$statusCode} {$duration}ms [{$traceId}]";
                if ($statusCode >= 500) {
                    Log::error($logMessage, $logContext);
                } elseif ($statusCode >= 400) {
                    Log::warning($logMessage, $logContext);
                } else {
                    Log::info($logMessage, $logContext);
                }
            }

            return $response;
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

            $span->setAttribute(SemanticAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
            $span->setAttribute('http.duration_ms', $duration);
            $span->setAttribute('error.message', $e->getMessage());
            $span->setAttribute('error.type', get_class($e));
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);

            Log::error("{$method} /{$route} {$statusCode} {$duration}ms [{$traceId}] {$e->getMessage()}", [
                SemanticAttributes::HTTP_REQUEST_METHOD       => $method,
                SemanticAttributes::HTTP_ROUTE                => "/{$route}",
                SemanticAttributes::HTTP_RESPONSE_STATUS_CODE => $statusCode,
                'http.duration_ms'                            => $duration,
                SemanticAttributes::OTEL_PROFILE              => $profileName,
                SemanticAttributes::LOG_EVENT            => SemanticAttributes::LOG_EVENT_ERROR,
                SemanticAttributes::LOG_TITLE            => "{$method} /{$route} {$statusCode} {$duration}ms [{$traceId}]",
                'error' => [
                    'message' => $e->getMessage(),
                    'type'    => get_class($e),
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
            } elseif (is_bool($value)) {
                // OTel PHP SDK stores booleans in attributes_bool column
                $result[$attrKey] = $value;
            } elseif (is_int($value) || is_float($value)) {
                // Preserve numeric types for attributes_number column in ClickHouse
                $result[$attrKey] = $value;
            } elseif (is_scalar($value)) {
                $result[$attrKey] = (string) $value;
            }
        }
        return $result;
    }
}
