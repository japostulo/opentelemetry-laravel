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
        $span->setAttribute('http.url', $request->fullUrl());
        if ($isPreflight) {
            $span->setAttribute(SemanticAttributes::HTTP_IS_PREFLIGHT, true);
        }

        $baggage = $this->parseBaggageHeader((string) $request->header('baggage', ''));

        // ── User Identity ───────────────────────────────────────────────
        $userAttrs = $this->resolveUserAttributes($request, $baggage);
        foreach ($userAttrs as $key => $value) {
            $span->setAttribute($key, $value);
        }

        // ── Infrastructure / Hop Tracking ───────────────────────────────
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor) {
            $span->setAttribute('http.x_forwarded_for', $forwardedFor);
            $hops = array_map('trim', explode(',', $forwardedFor));
            $span->setAttribute('network.hop_count', count($hops));
            $span->setAttribute('http.client_ip', $hops[0]);
        }

        // ── Public Client IP ────────────────────────────────────────────
        $ipData = $this->applyPublicIpMode($this->resolvePublicClientIp($request, true));
        if ($ipData['publicIp'] !== null) {
            $span->setAttribute(SemanticAttributes::CLIENT_PUBLIC_IP, $ipData['publicIp']);
            $span->setAttribute(SemanticAttributes::CLIENT_IP_SOURCE, $ipData['source'] ?? '');
            if ($ipData['chainLength'] !== null) {
                $span->setAttribute(SemanticAttributes::CLIENT_IP_CHAIN_LENGTH, $ipData['chainLength']);
            }
            if ($ipData['rawChain'] !== null) {
                $span->setAttribute(SemanticAttributes::CLIENT_FORWARDED_FOR, $ipData['rawChain']);
            }
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

        // ── Baggage from frontend and upstream services ───────────────────
        $baggageClientIp = null;
        $baggageClientIpSource = null;
        if (!empty($baggage)) {
            $hasLocalIp = $ipData['publicIp'] !== null;
            foreach ($baggage as $key => $value) {
                if (preg_match('/^(page\.|browser\.|device\.|app\.)/', $key)) {
                    $span->setAttribute($key, $value);
                } elseif (!$hasLocalIp && $key === SemanticAttributes::CLIENT_PUBLIC_IP) {
                    // Propagated IP from upstream entry-point service
                    $span->setAttribute($key, $value);
                    $baggageClientIp = $value;
                } elseif (!$hasLocalIp && $key === SemanticAttributes::CLIENT_IP_SOURCE) {
                    $span->setAttribute($key, $value);
                    $baggageClientIpSource = $value;
                }
            }
        }

        // Effective IP for log contexts (local > baggage)
        $effectiveClientIp = $ipData['publicIp'] ?? $baggageClientIp;
        $effectiveClientIpSource = ($ipData['publicIp'] !== null ? ($ipData['source'] ?? null) : $baggageClientIpSource);

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
            ] + $userAttrs;

            if ($effectiveClientIp !== null) {
                $reqLogCtx[SemanticAttributes::CLIENT_PUBLIC_IP] = $effectiveClientIp;
                $reqLogCtx[SemanticAttributes::CLIENT_IP_SOURCE] = $effectiveClientIpSource ?? '';
            }

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
                ] + $userAttrs;

                if ($effectiveClientIp !== null) {
                    $logContext[SemanticAttributes::CLIENT_PUBLIC_IP] = $effectiveClientIp;
                    $logContext[SemanticAttributes::CLIENT_IP_SOURCE] = $effectiveClientIpSource ?? '';
                }

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

            $errorLogCtx = [
                SemanticAttributes::HTTP_REQUEST_METHOD       => $method,
                SemanticAttributes::HTTP_ROUTE                => "/{$route}",
                SemanticAttributes::HTTP_RESPONSE_STATUS_CODE => $statusCode,
                'http.duration_ms'                            => $duration,
                SemanticAttributes::OTEL_PROFILE              => $profileName,
                SemanticAttributes::LOG_EVENT            => SemanticAttributes::LOG_EVENT_ERROR,
                SemanticAttributes::LOG_TITLE            => "{$method} /{$route} {$statusCode} {$duration}ms [{$traceId}]",
                'error.message'                          => $e->getMessage(),
                'error.type'                             => get_class($e),
            ] + $userAttrs;

            if ($effectiveClientIp !== null) {
                $errorLogCtx[SemanticAttributes::CLIENT_PUBLIC_IP] = $effectiveClientIp;
                $errorLogCtx[SemanticAttributes::CLIENT_IP_SOURCE] = $effectiveClientIpSource ?? '';
            }

            Log::error("{$method} /{$route} {$statusCode} {$duration}ms [{$traceId}] {$e->getMessage()}", $errorLogCtx);

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
            } elseif (is_string($value)) {
                $sanitized[$key] = PayloadSanitizer::redactSensitiveValue($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    /** @return array<string, string> */
    private function parseBaggageHeader(string $header): array
    {
        $out = [];
        if ($header === '') return $out;
        foreach (explode(',', $header) as $entry) {
            $parts = explode('=', trim($entry), 2);
            if (count($parts) !== 2) continue;
            $out[trim($parts[0])] = urldecode(trim($parts[1]));
        }
        return $out;
    }

    /** @param array<string, string> $baggage @return array<string, string> */
    private function resolveUserAttributes(Request $request, array $baggage): array
    {
        $identity = $this->identityFromLaravelAuth($request)
            ?? $this->identityFromConfiguredResolver($request)
            ?? $this->identityFromBaggage($baggage);

        if ($identity === null) {
            return ['user.type' => 'anonymous'];
        }

        return $this->identityToAttributes($identity);
    }

    /** @return array<string, mixed>|null */
    private function identityFromLaravelAuth(Request $request): ?array
    {
        $user = $request->user();
        if (!$user) return null;
        $identity = [
            'id' => (string) $user->getAuthIdentifier(),
            'type' => 'authenticated',
        ];
        if (isset($user->role)) $identity['role'] = (string) $user->role;
        if (method_exists($user, 'getRole')) $identity['role'] = (string) $user->getRole();
        if (isset($user->tenant_id)) $identity['tenant_id'] = (string) $user->tenant_id;
        if ((bool) config('otel.identity.include_email', false)) {
            if (method_exists($user, 'getEmail')) $identity['email'] = (string) $user->getEmail();
            elseif (isset($user->email)) $identity['email'] = (string) $user->email;
        }
        return $identity;
    }

    /** @return array<string, mixed>|null */
    private function identityFromConfiguredResolver(Request $request): ?array
    {
        $resolver = config('otel.identity.resolver');
        if (!$resolver) return null;
        if (is_callable($resolver)) {
            $result = $resolver($request);
            return is_array($result) ? $result : null;
        }
        if (is_string($resolver) && class_exists($resolver)) {
            $instance = app($resolver);
            if (is_callable($instance)) {
                $result = $instance($request);
                return is_array($result) ? $result : null;
            }
        }
        return null;
    }

    /** @param array<string, string> $baggage @return array<string, mixed>|null */
    private function identityFromBaggage(array $baggage): ?array
    {
        $keys = ['user.id', 'user.type', 'user.role', 'user.tenant_id', 'user.session_id', 'user.auth_provider', 'user.email'];
        $hasAny = false;
        foreach ($keys as $key) {
            if (array_key_exists($key, $baggage)) {
                $hasAny = true;
                break;
            }
        }
        if (!$hasAny) return null;
        return [
            'id' => $baggage['user.id'] ?? 'anonymous',
            'type' => $baggage['user.type'] ?? (isset($baggage['user.id']) ? 'authenticated' : 'anonymous'),
            'role' => $baggage['user.role'] ?? null,
            'tenant_id' => $baggage['user.tenant_id'] ?? null,
            'session_id' => $baggage['user.session_id'] ?? null,
            'auth_provider' => $baggage['user.auth_provider'] ?? null,
            'email' => $baggage['user.email'] ?? null,
        ];
    }

    /** @param array<string, mixed> $identity @return array<string, string> */
    private function identityToAttributes(array $identity): array
    {
        $attrs = ['user.type' => (string) ($identity['type'] ?? 'authenticated')];
        $id = isset($identity['id']) ? $this->applyUserIdMode((string) $identity['id']) : null;
        if ($id !== null) $attrs['user.id'] = $id;
        foreach ([
            'role' => 'user.role',
            'tenant_id' => 'user.tenant_id',
            'session_id' => 'user.session_id',
            'auth_provider' => 'user.auth_provider',
        ] as $source => $attr) {
            if (!empty($identity[$source])) $attrs[$attr] = (string) $identity[$source];
        }
        if ((bool) config('otel.identity.include_email', false) && !empty($identity['email'])) {
            $attrs['user.email'] = (string) $identity['email'];
        }
        return $attrs;
    }

    private function applyUserIdMode(string $id): ?string
    {
        $mode = (string) config('otel.identity.user_id_mode', 'raw');
        if ($mode === 'off') return null;
        if ($mode === 'hash') return hash('sha256', (string) config('otel.identity.hash_salt', '') . ':' . $id);
        return $id;
    }

    /** @param array{publicIp: string|null, source: string|null, chainLength: int|null, rawChain: string|null} $ipData */
    private function applyPublicIpMode(array $ipData): array
    {
        $mode = (string) config('otel.privacy.public_ip_mode', 'raw');
        if ($mode === 'off') {
            $ipData['publicIp'] = null;
            $ipData['rawChain'] = null;
        } elseif ($mode === 'hash' && $ipData['publicIp'] !== null) {
            $ipData['publicIp'] = hash('sha256', (string) config('otel.privacy.hash_salt', '') . ':' . $ipData['publicIp']);
            $ipData['rawChain'] = null;
        }
        return $ipData;
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

    /**
     * Returns true when the IP address is private, loopback, link-local or
     * otherwise non-routable on the public Internet.
     */
    private function isPrivateIp(string $ip): bool
    {
        // Strip port suffix: IPv6 bracket [::1]:443 or IPv4 1.2.3.4:80
        if (preg_match('/^\[(.+)\](?::\d+)?$/', $ip, $m)) {
            $ip = $m[1];
        } elseif (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}):\d+$/', $ip, $m)) {
            $ip = $m[1];
        }
        $ip = trim($ip);
        if ($ip === '') return true;

        // Normalise IPv4-mapped IPv6 (::ffff:x.x.x.x) → plain IPv4
        if (preg_match('/^::ffff:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/i', $ip, $m)) {
            $ip = $m[1];
        }

        // IPv6 loopback and link-local
        if ($ip === '::1') return true;
        if (stripos($ip, 'fe80:') === 0) return true;
        if (preg_match('/^f[cd]/i', $ip)) return true;
        if ($ip === '::') return true;

        // IPv4 private / loopback / link-local
        if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})/', $ip, $o)) {
            $a = (int) $o[1];
            $b = (int) $o[2];
            if ($a === 127) return true;
            if ($a === 10) return true;
            if ($a === 172 && $b >= 16 && $b <= 31) return true;
            if ($a === 192 && $b === 168) return true;
            if ($a === 169 && $b === 254) return true;
            if ($a >= 224) return true; // multicast + broadcast
            if ($a === 0) return true;
        }
        return false;
    }

    /**
     * Derives the public client IP from request headers using the priority:
     *   1. Forwarded (RFC 7239)
     *   2. CF-Connecting-IP
     *   3. X-Forwarded-For (first public IP)
     *   4. X-Real-IP
     *
     * @return array{publicIp: string|null, source: string|null, chainLength: int|null, rawChain: string|null}
     */
    private function resolvePublicClientIp(Request $request, bool $trustProxy): array
    {
        $result = ['publicIp' => null, 'source' => null, 'chainLength' => null, 'rawChain' => null];

        if ($trustProxy) {
            // 1. Forwarded (RFC 7239)
            $forwarded = $request->header('Forwarded');
            if ($forwarded) {
                if (preg_match('/\bfor=[\'"\[]?([^\s,;\'"\]]+)/i', $forwarded, $m)) {
                    $ip = trim($m[1], '"\' ');
                    if ($ip && !$this->isPrivateIp($ip)) {
                        $result['publicIp'] = $ip;
                        $result['source'] = 'forwarded';
                        return $result;
                    }
                }
            }

            // 2. CF-Connecting-IP
            $cfIp = $request->header('CF-Connecting-IP');
            if ($cfIp && !$this->isPrivateIp($cfIp)) {
                $result['publicIp'] = trim($cfIp);
                $result['source'] = 'cf-connecting-ip';
                return $result;
            }

            // 3. X-Forwarded-For
            $xff = $request->header('X-Forwarded-For');
            if ($xff) {
                $hops = array_map('trim', explode(',', $xff));
                $result['chainLength'] = count($hops);
                $result['rawChain'] = $xff;
                foreach ($hops as $hop) {
                    if ($hop && !$this->isPrivateIp($hop)) {
                        $result['publicIp'] = $hop;
                        $result['source'] = 'x-forwarded-for';
                        return $result;
                    }
                }
                return $result; // chain present but no public IP
            }

            // 4. X-Real-IP
            $realIp = $request->header('X-Real-IP');
            if ($realIp && !$this->isPrivateIp($realIp)) {
                $result['publicIp'] = trim($realIp);
                $result['source'] = 'x-real-ip';
                return $result;
            }
        }

        // 5. Socket (REMOTE_ADDR via Laravel)
        $remoteAddr = $request->server('REMOTE_ADDR', '');
        if ($remoteAddr && !$this->isPrivateIp($remoteAddr)) {
            $result['publicIp'] = $remoteAddr;
            $result['source'] = 'socket';
        }

        return $result;
    }
}
