<?php

declare(strict_types=1);

namespace Haoc\OpenTelemetry\Tests\Feature\Middleware;

use Haoc\OpenTelemetry\Attributes\SemanticAttributes;
use Haoc\OpenTelemetry\Middleware\TraceRequest;
use Haoc\OpenTelemetry\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Events\MessageLogged;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use Orchestra\Testbench\TestCase;

/**
 * Consumption / integration tests for TraceRequest middleware.
 *
 * Uses the Illuminate\Log\Events\MessageLogged event for log capture instead
 * of Log::fake() so it works with any Laravel 11+ minor version.
 */
final class TraceRequestTest extends TestCase
{
    /**
     * All log entries emitted during the current test.
     *
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private array $loggedMessages = [];

    // ── Testbench setup ──────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        $this->loggedMessages = [];

        // Capture every log entry via the framework event (works regardless of
        // driver, no Log::fake() needed, compatible with all Laravel 11+ versions).
        $this->app->make('events')->listen(
            MessageLogged::class,
            function (MessageLogged $e): void {
                $this->loggedMessages[] = [
                    'level'   => $e->level,
                    'message' => $e->message,
                    'context' => $e->context,
                ];
            },
        );
    }

    protected function defineEnvironment($app): void
    {
        // Null channel — no disk writes; MessageLogged is still dispatched.
        $app['config']->set('logging.channels.null', [
            'driver'  => 'monolog',
            'handler' => \Monolog\Handler\NullHandler::class,
        ]);
        $app['config']->set('logging.default', 'null');

        $app['config']->set('haoc-otel', [
            'sensitive_fields' => [],
            'environment'      => 'testing',
        ]);
    }

    // ── Assertion helpers ────────────────────────────────────────────

    /**
     * Assert that at least one INFO log entry satisfies the given predicate.
     *
     * @param \Closure(string, array<string,mixed>): bool $filter
     */
    private function assertLoggedInfo(\Closure $filter, string $failMessage = ''): void
    {
        $infos   = array_filter($this->loggedMessages, fn($e) => $e['level'] === 'info');
        $matches = array_filter($infos, fn($e) => $filter($e['message'], $e['context']));

        $this->assertNotEmpty(
            $matches,
            $failMessage ?: 'Expected at least one matching INFO log entry',
        );
    }

    private function assertNoLogs(): void
    {
        $this->assertEmpty(
            $this->loggedMessages,
            sprintf('Expected no log records but got %d', count($this->loggedMessages)),
        );
    }

    // ── Mock factory helpers ─────────────────────────────────────────

    /** Build a tracer mock (span attrs discarded). */
    private function buildTracer(): TracerInterface
    {
        return $this->buildCapturingTracer($ignored);
    }

    /**
     * Build a tracer mock that stores every setAttribute call in $attrs.
     *
     * @param array<string, mixed> $attrs  Populated by reference during the test.
     */
    private function buildCapturingTracer(mixed &$attrs): TracerInterface
    {
        $attrs = [];

        $scope = $this->createMock(ScopeInterface::class);
        $scope->method('detach')->willReturn(0);

        $spanContext = $this->createMock(SpanContextInterface::class);
        $spanContext->method('getTraceId')->willReturn('aabbccdd11223344aabbccdd11223344');

        $span = $this->createMock(SpanInterface::class);
        $span->method('getContext')->willReturn($spanContext);
        $span->method('activate')->willReturn($scope);
        $span->method('setStatus')->willReturnSelf();
        $span->method('recordException')->willReturnSelf();
        $span->method('setAttribute')
            ->willReturnCallback(function (string $key, mixed $value) use (&$attrs, $span) {
                $attrs[$key] = $value;
                return $span;
            });

        $spanBuilder = $this->createMock(SpanBuilderInterface::class);
        $spanBuilder->method('setSpanKind')->willReturnSelf();
        $spanBuilder->method('setParent')->willReturnSelf();
        $spanBuilder->method('startSpan')->willReturn($span);

        $tracer = $this->createMock(TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($spanBuilder);

        return $tracer;
    }

    /** Build a Profile for the given name with testing defaults. */
    private function buildProfile(string $name): Profile
    {
        return Profile::fromConfig([
            'profile'               => $name,
            'sample_ratio'          => null,
            'capture_request_body'  => null,
            'capture_response_body' => null,
            'ignore_routes'         => [],
            'log_destination'       => 'both',
            'environment'           => 'testing',
        ]);
    }

    /** Build a POST request with a JSON body. */
    private function jsonPostRequest(string $uri, array $body): Request
    {
        return Request::create(
            $uri,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body),
        );
    }

    // ── standard: request body in log ───────────────────────────────

    public function test_standard_logs_request_body_as_haoc_request_json(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('standard'));
        $req = $this->jsonPostRequest('/api/users', ['name' => 'Alice', 'role' => 'admin']);

        $mw->handle($req, fn() => new JsonResponse(['id' => 1], 201));

        $this->assertLoggedInfo(
            fn($msg, $ctx) => isset($ctx[SemanticAttributes::HAOC_REQUEST_JSON]),
            'standard profile must emit request.json in the request log',
        );
    }

    public function test_standard_request_json_contains_correct_payload(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('standard'));
        $req = $this->jsonPostRequest('/api/users', ['name' => 'Alice', 'role' => 'admin']);

        $mw->handle($req, fn() => new JsonResponse(['id' => 1], 201));

        $this->assertLoggedInfo(function ($msg, $ctx): bool {
            if (!isset($ctx[SemanticAttributes::HAOC_REQUEST_JSON])) {
                return false;
            }
            $body = json_decode($ctx[SemanticAttributes::HAOC_REQUEST_JSON], true);
            return ($body['name'] ?? null) === 'Alice'
                && ($body['role'] ?? null) === 'admin';
        });
    }

    // ── standard: response body in log ──────────────────────────────

    public function test_standard_logs_response_body_as_haoc_response_json(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('standard'));
        $req = Request::create('/api/users/1', 'GET');

        $mw->handle($req, fn() => new JsonResponse(['id' => 1, 'name' => 'Alice'], 200));

        $this->assertLoggedInfo(
            fn($msg, $ctx) => isset($ctx[SemanticAttributes::HAOC_RESPONSE_JSON]),
            'standard profile must emit response.json in the response log',
        );
    }

    public function test_standard_response_json_contains_correct_payload(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('standard'));
        $req = Request::create('/api/users/1', 'GET');

        $mw->handle($req, fn() => new JsonResponse(['id' => 1, 'name' => 'Alice'], 200));

        $this->assertLoggedInfo(function ($msg, $ctx): bool {
            if (!isset($ctx[SemanticAttributes::HAOC_RESPONSE_JSON])) {
                return false;
            }
            $body = json_decode($ctx[SemanticAttributes::HAOC_RESPONSE_JSON], true);
            return ($body['id'] ?? null) === 1
                && ($body['name'] ?? null) === 'Alice';
        });
    }

    // ── standard: body NOT in span attributes ────────────────────────

    public function test_standard_does_not_flatten_body_into_span_attributes(): void
    {
        $spanAttrs = [];
        $mw = new TraceRequest($this->buildCapturingTracer($spanAttrs), $this->buildProfile('standard'));
        $req = $this->jsonPostRequest('/api/users', ['name' => 'Alice']);

        $mw->handle($req, fn() => new JsonResponse(['id' => 1], 201));

        $bodyKeys = array_filter(array_keys($spanAttrs), fn($k) => str_starts_with($k, 'request.body.'));
        $this->assertEmpty($bodyKeys, 'standard must not flatten request body into span attributes');
    }

    public function test_standard_does_not_flatten_response_into_span_attributes(): void
    {
        $spanAttrs = [];
        $mw = new TraceRequest($this->buildCapturingTracer($spanAttrs), $this->buildProfile('standard'));
        $req = Request::create('/api/users/1', 'GET');

        $mw->handle($req, fn() => new JsonResponse(['id' => 1, 'name' => 'Alice'], 200));

        $bodyKeys = array_filter(array_keys($spanAttrs), fn($k) => str_starts_with($k, 'response.body.'));
        $this->assertEmpty($bodyKeys, 'standard must not flatten response body into span attributes');
    }

    // ── sensitive fields: redaction in log ──────────────────────────

    public function test_standard_redacts_password_in_request_log(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('standard'));
        $req = $this->jsonPostRequest('/api/login', [
            'username' => 'alice',
            'password' => 'super-secret',
        ]);

        $mw->handle($req, fn() => new JsonResponse(['user_id' => 1], 200));

        $this->assertLoggedInfo(function ($msg, $ctx): bool {
            if (!isset($ctx[SemanticAttributes::HAOC_REQUEST_JSON])) {
                return false;
            }
            $body = json_decode($ctx[SemanticAttributes::HAOC_REQUEST_JSON], true);
            return ($body['password'] ?? null) === '[REDACTED]'
                && ($body['username'] ?? null) === 'alice';
        }, 'password field must be redacted in the request log');
    }

    public function test_standard_redacts_token_in_response_log(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('standard'));
        $req = Request::create('/api/login', 'POST');

        $mw->handle($req, fn() => new JsonResponse(['user_id' => 42, 'token' => 'jwt.secret'], 200));

        $this->assertLoggedInfo(function ($msg, $ctx): bool {
            if (!isset($ctx[SemanticAttributes::HAOC_RESPONSE_JSON])) {
                return false;
            }
            $body = json_decode($ctx[SemanticAttributes::HAOC_RESPONSE_JSON], true);
            return ($body['token'] ?? null) === '[REDACTED]'
                && ($body['user_id'] ?? null) === 42;
        }, 'token field must be redacted in the response log');
    }

    // ── minimal: no body anywhere ────────────────────────────────────

    public function test_minimal_does_not_log_request_body(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('minimal'));
        $req = $this->jsonPostRequest('/api/users', ['name' => 'Alice']);

        $mw->handle($req, fn() => new JsonResponse(['id' => 1], 201));

        $this->assertLoggedInfo(
            fn($msg, $ctx) => !isset($ctx[SemanticAttributes::HAOC_REQUEST_JSON]),
            'minimal profile must not emit request.json',
        );
    }

    public function test_minimal_does_not_log_response_body(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('minimal'));
        $req = Request::create('/api/users/1', 'GET');

        $mw->handle($req, fn() => new JsonResponse(['id' => 1], 200));

        $this->assertLoggedInfo(
            fn($msg, $ctx) => !isset($ctx[SemanticAttributes::HAOC_RESPONSE_JSON]),
            'minimal profile must not emit response.json',
        );
    }

    // ── verbose: body in span AND log ────────────────────────────────

    public function test_verbose_flattens_body_into_span_and_logs_json_attr(): void
    {
        $spanAttrs = [];
        $mw = new TraceRequest($this->buildCapturingTracer($spanAttrs), $this->buildProfile('verbose'));
        $req = $this->jsonPostRequest('/api/users', ['name' => 'Alice', 'role' => 'admin']);

        $mw->handle($req, fn() => new JsonResponse(['id' => 1], 201));

        // Span MUST contain flattened body
        $this->assertArrayHasKey(
            'body.name',
            $spanAttrs,
            'verbose must flatten request body into span attributes',
        );
        $this->assertSame('Alice', $spanAttrs['body.name']);

        // Log MUST also carry the JSON attr
        $this->assertLoggedInfo(
            fn($msg, $ctx) => isset($ctx[SemanticAttributes::HAOC_REQUEST_JSON]),
        );
    }

    public function test_verbose_flattens_response_into_span_and_logs_json_attr(): void
    {
        $spanAttrs = [];
        $mw = new TraceRequest($this->buildCapturingTracer($spanAttrs), $this->buildProfile('verbose'));
        $req = Request::create('/api/users/1', 'GET');

        $mw->handle($req, fn() => new JsonResponse(['id' => 1, 'name' => 'Alice'], 200));

        // Span MUST contain flattened response
        $this->assertArrayHasKey(
            'response.body.name',
            $spanAttrs,
            'verbose must flatten response body into span attributes',
        );

        // Log MUST also carry the JSON attr
        $this->assertLoggedInfo(
            fn($msg, $ctx) => isset($ctx[SemanticAttributes::HAOC_RESPONSE_JSON]),
        );
    }

    // ── OPTIONS (preflight) ──────────────────────────────────────────

    public function test_options_produces_no_log_for_standard_profile(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('standard'));
        $req = Request::create('/api/users', 'OPTIONS');

        $mw->handle($req, fn() => new Response('', 204));

        $this->assertNoLogs();
    }

    public function test_options_produces_no_log_for_minimal_profile(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('minimal'));
        $req = Request::create('/api/users', 'OPTIONS');

        $mw->handle($req, fn() => new Response('', 204));

        $this->assertNoLogs();
    }

    public function test_options_sets_is_preflight_span_attribute(): void
    {
        $spanAttrs = [];
        $mw = new TraceRequest($this->buildCapturingTracer($spanAttrs), $this->buildProfile('standard'));
        $req = Request::create('/api/users', 'OPTIONS');

        $mw->handle($req, fn() => new Response('', 204));

        $this->assertTrue(
            $spanAttrs[SemanticAttributes::HAOC_IS_PREFLIGHT] ?? false,
            'OPTIONS request must set http.is_preflight=true on the span',
        );
    }

    public function test_options_is_logged_for_verbose_profile(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('verbose'));
        $req = Request::create('/api/users', 'OPTIONS');

        $mw->handle($req, fn() => new Response('', 204));

        $this->assertLoggedInfo(
            fn($msg, $ctx) => isset($ctx[SemanticAttributes::HAOC_LOG_EVENT]),
            'verbose profile must log OPTIONS (preflight) requests',
        );
    }

    // ── Log event values ─────────────────────────────────────────────

    public function test_standard_request_log_uses_correct_event_value(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('standard'));
        $req = Request::create('/api/users', 'GET');

        $mw->handle($req, fn() => new JsonResponse([], 200));

        $this->assertLoggedInfo(
            fn($msg, $ctx) => ($ctx[SemanticAttributes::HAOC_LOG_EVENT] ?? null) === SemanticAttributes::LOG_EVENT_REQUEST,
        );
    }

    public function test_standard_response_log_uses_correct_event_value(): void
    {
        $mw = new TraceRequest($this->buildTracer(), $this->buildProfile('standard'));
        $req = Request::create('/api/users', 'GET');

        $mw->handle($req, fn() => new JsonResponse([], 200));

        $this->assertLoggedInfo(
            fn($msg, $ctx) => ($ctx[SemanticAttributes::HAOC_LOG_EVENT] ?? null) === SemanticAttributes::LOG_EVENT_RESPONSE,
        );
    }

    // ── env override: logPayloadMode=flatten ─────────────────────────

    public function test_env_override_flatten_logs_body_for_standard_profile(): void
    {
        putenv('OTEL_LOG_PAYLOAD_MODE=flatten');

        try {
            $profile = $this->buildProfile('standard'); // picks up env override
            $mw      = new TraceRequest($this->buildTracer(), $profile);
            $req     = $this->jsonPostRequest('/api/users', ['name' => 'Alice']);

            $mw->handle($req, fn() => new JsonResponse(['id' => 1], 201));

            $this->assertLoggedInfo(
                fn($msg, $ctx) => isset($ctx['body.name']),
                'OTEL_LOG_PAYLOAD_MODE=flatten must produce flattened body keys in the log',
            );
        } finally {
            putenv('OTEL_LOG_PAYLOAD_MODE');
        }
    }
}
