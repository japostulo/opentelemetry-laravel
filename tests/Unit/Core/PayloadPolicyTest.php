<?php

declare(strict_types=1);

namespace Haoc\OpenTelemetry\Tests\Unit\Core;

use Haoc\OpenTelemetry\Payload\PayloadPolicy;
use PHPUnit\Framework\TestCase;

class PayloadPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure env overrides are clear before each test
        putenv('OTEL_MAX_REQUEST_BODY_BYTES');
        putenv('OTEL_MAX_RESPONSE_BODY_BYTES');
        putenv('OTEL_MAX_ATTRIBUTE_VALUE_BYTES');
        putenv('OTEL_LOG_PAYLOAD_MODE');
    }

    protected function tearDown(): void
    {
        putenv('OTEL_MAX_REQUEST_BODY_BYTES');
        putenv('OTEL_MAX_RESPONSE_BODY_BYTES');
        putenv('OTEL_MAX_ATTRIBUTE_VALUE_BYTES');
        putenv('OTEL_LOG_PAYLOAD_MODE');
    }

    // ── Profile defaults ─────────────────────────────────────────────

    public function test_minimal_max_bytes_are_zero(): void
    {
        $p = new PayloadPolicy('minimal');
        $this->assertSame(0, $p->getMaxRequestBytes());
        $this->assertSame(0, $p->getMaxResponseBytes());
    }

    public function test_minimal_payload_modes_are_none(): void
    {
        $p = new PayloadPolicy('minimal');
        $this->assertSame('none', $p->getSpanPayloadMode());
        $this->assertSame('none', $p->getLogPayloadMode());
    }

    public function test_standard_max_request_bytes_is_16kb(): void
    {
        $p = new PayloadPolicy('standard');
        $this->assertSame(16 * 1024, $p->getMaxRequestBytes());
        $this->assertSame(16 * 1024, $p->getMaxResponseBytes());
    }

    public function test_standard_span_mode_none_log_mode_json_attr(): void
    {
        $p = new PayloadPolicy('standard');
        $this->assertSame('none', $p->getSpanPayloadMode());
        $this->assertSame('json-attr', $p->getLogPayloadMode());
    }

    public function test_verbose_max_request_bytes_is_64kb(): void
    {
        $p = new PayloadPolicy('verbose');
        $this->assertSame(64 * 1024, $p->getMaxRequestBytes());
        $this->assertSame(64 * 1024, $p->getMaxResponseBytes());
    }

    public function test_verbose_span_mode_flatten(): void
    {
        $p = new PayloadPolicy('verbose');
        $this->assertSame('flatten', $p->getSpanPayloadMode());
        $this->assertSame('json-attr', $p->getLogPayloadMode());
    }

    public function test_default_max_attribute_bytes(): void
    {
        $p = new PayloadPolicy('standard');
        $this->assertSame(PayloadPolicy::DEFAULT_MAX_ATTRIBUTE_BYTES, $p->getMaxAttributeBytes());
        $this->assertSame(64 * 1024, PayloadPolicy::DEFAULT_MAX_ATTRIBUTE_BYTES);
    }

    // ── Env variable overrides ───────────────────────────────────────

    public function test_env_max_request_body_bytes_overrides_default(): void
    {
        putenv('OTEL_MAX_REQUEST_BODY_BYTES=4096');
        $p = new PayloadPolicy('standard');
        $this->assertSame(4096, $p->getMaxRequestBytes());
    }

    public function test_env_max_response_body_bytes_overrides_default(): void
    {
        putenv('OTEL_MAX_RESPONSE_BODY_BYTES=8192');
        $p = new PayloadPolicy('standard');
        $this->assertSame(8192, $p->getMaxResponseBytes());
    }

    public function test_env_max_attribute_bytes_overrides_default(): void
    {
        putenv('OTEL_MAX_ATTRIBUTE_VALUE_BYTES=2048');
        $p = new PayloadPolicy('standard');
        $this->assertSame(2048, $p->getMaxAttributeBytes());
    }

    public function test_env_log_payload_mode_overrides_profile(): void
    {
        putenv('OTEL_LOG_PAYLOAD_MODE=flatten');
        $p = new PayloadPolicy('standard');
        $this->assertSame('flatten', $p->getLogPayloadMode());
    }

    public function test_invalid_env_log_payload_mode_is_ignored(): void
    {
        putenv('OTEL_LOG_PAYLOAD_MODE=invalid-mode');
        $p = new PayloadPolicy('standard');
        // Falls back to profile default
        $this->assertSame('json-attr', $p->getLogPayloadMode());
    }

    // ── Programmatic overrides (highest precedence) ──────────────────

    public function test_programmatic_max_request_bytes_wins_over_env(): void
    {
        putenv('OTEL_MAX_REQUEST_BODY_BYTES=4096');
        $p = new PayloadPolicy('standard', ['maxRequestBytes' => 1024]);
        $this->assertSame(1024, $p->getMaxRequestBytes());
    }

    public function test_programmatic_max_response_bytes_wins_over_profile(): void
    {
        $p = new PayloadPolicy('minimal', ['maxResponseBytes' => 32768]);
        $this->assertSame(32768, $p->getMaxResponseBytes());
    }

    public function test_programmatic_span_payload_mode_overrides(): void
    {
        $p = new PayloadPolicy('minimal', ['spanPayloadMode' => 'json-attr']);
        $this->assertSame('json-attr', $p->getSpanPayloadMode());
    }

    public function test_programmatic_log_payload_mode_wins_over_env(): void
    {
        putenv('OTEL_LOG_PAYLOAD_MODE=none');
        $p = new PayloadPolicy('verbose', ['logPayloadMode' => 'flatten']);
        $this->assertSame('flatten', $p->getLogPayloadMode());
    }

    // ── Helper methods ───────────────────────────────────────────────

    public function test_should_capture_span_payload_false_for_none(): void
    {
        $p = new PayloadPolicy('standard');
        $this->assertFalse($p->shouldCaptureSpanPayload());
    }

    public function test_should_capture_span_payload_true_for_flatten(): void
    {
        $p = new PayloadPolicy('verbose');
        $this->assertTrue($p->shouldCaptureSpanPayload());
    }

    public function test_should_capture_log_payload_false_for_minimal(): void
    {
        $p = new PayloadPolicy('minimal');
        $this->assertFalse($p->shouldCaptureLogPayload());
    }

    public function test_should_capture_log_payload_true_for_standard(): void
    {
        $p = new PayloadPolicy('standard');
        $this->assertTrue($p->shouldCaptureLogPayload());
    }

    // ── Unknown profile fallback ─────────────────────────────────────

    public function test_unknown_profile_falls_back_to_minimal(): void
    {
        $p = new PayloadPolicy('nonexistent');
        $this->assertSame(0, $p->getMaxRequestBytes());
        $this->assertSame('none', $p->getLogPayloadMode());
    }
}
