<?php

declare(strict_types=1);

namespace Haoc\OpenTelemetry\Tests\Unit\Logging;

use Haoc\OpenTelemetry\Logging\OtelHandler;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Logs\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OtelHandler::buildBody().
 *
 * When the log context contains a request or response payload the body must
 * be the decoded payload JSON so SigNoz activates the tree view.
 * For logs without a payload the body falls back to {"msg":"..."}.
 */
final class OtelHandlerTest extends TestCase
{
    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Expose the protected buildBody() method via an anonymous subclass.
     */
    private function buildBody(LogRecord $record): string
    {
        $logger = $this->createMock(LoggerInterface::class);

        $sut = new class ($logger, Level::Debug, true, true) extends OtelHandler {
            public function invoke(LogRecord $r): string
            {
                return $this->buildBody($r);
            }
        };

        return $sut->invoke($record);
    }

    private function makeRecord(string $message, array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel:  'test',
            level:    Level::Info,
            message:  $message,
            context:  $context,
        );
    }

    // ── Body is JSON when payload present, plain string otherwise ────

    public function test_body_is_valid_json(): void
    {
        $payload = '{"name":"Alice"}';
        $body    = $this->buildBody($this->makeRecord('POST /api/test [abc]', [
            'request.json' => $payload,
        ]));

        $this->assertNotFalse(
            json_decode($body),
            'OtelHandler body must be a valid JSON string when payload is present',
        );
    }

    // ── Fallback: no payload → plain string ──────────────────────────

    public function test_body_fallback_is_msg_when_no_payload(): void
    {
        $message = 'GET /api/test [abc]';
        $body    = $this->buildBody($this->makeRecord($message));

        $this->assertSame($message, $body,
            'Without payload body must be the plain log message string',
        );
    }

    public function test_body_fallback_ignores_non_payload_context(): void
    {
        $message = 'POST /api/test [abc]';
        $body    = $this->buildBody($this->makeRecord($message, [
            'http.request.method'       => 'POST',
            'http.response.status_code' => 201,
            'otel.profile'         => 'standard',
        ]));

        $this->assertSame($message, $body);
    }

    // ── Request payload → body = decoded payload ─────────────────────

    public function test_body_is_request_payload_when_request_json_present(): void
    {
        $payload = '{"name":"Alice","password":"[REDACTED]"}';
        $body    = $this->buildBody($this->makeRecord('POST /api/users [abc]', [
            'request.json' => $payload,
        ]));
        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded);
        $this->assertSame('Alice', $decoded['name']);
        $this->assertSame('[REDACTED]', $decoded['password']);
        $this->assertArrayNotHasKey('msg', $decoded,
            'Body must be the raw payload, not wrapped in {"msg":"..."}',
        );
    }

    public function test_body_fallback_when_request_json_is_invalid(): void
    {
        $message = 'POST /test [abc]';
        $body    = $this->buildBody($this->makeRecord($message, [
            'request.json' => '{not valid json}',
        ]));

        $this->assertSame($message, $body);
    }

    // ── Response payload → body = decoded payload ────────────────────

    public function test_body_is_response_payload_when_response_json_present(): void
    {
        $payload = '{"id":42,"token":"[REDACTED]","status":"ok"}';
        $body    = $this->buildBody($this->makeRecord('GET /api/users/42 200 [abc]', [
            'response.json' => $payload,
        ]));
        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded);
        $this->assertSame(42, $decoded['id']);
        $this->assertSame('[REDACTED]', $decoded['token']);
        $this->assertArrayNotHasKey('msg', $decoded);
    }

    public function test_body_fallback_when_response_json_is_invalid(): void
    {
        $message = 'GET /test [abc]';
        $body    = $this->buildBody($this->makeRecord($message, [
            'response.json' => 'not-json',
        ]));

        $this->assertSame($message, $body);
    }

    // ── Priority: request wins over response ─────────────────────────

    public function test_request_json_takes_priority_over_response_json(): void
    {
        $body    = $this->buildBody($this->makeRecord('POST /api/test [abc]', [
            'request.json'  => '{"type":"request"}',
            'response.json' => '{"type":"response"}',
        ]));
        $decoded = json_decode($body, true);

        $this->assertSame('request', $decoded['type'],
            'request.json must take priority over response.json',
        );
    }
}
