<?php

declare(strict_types=1);

namespace Haoc\OpenTelemetry\Tests\Unit\Core;

use Haoc\OpenTelemetry\Sanitizer\PayloadSanitizer;
use PHPUnit\Framework\TestCase;

class PayloadSanitizerTest extends TestCase
{
    // ── isBinaryContent() ────────────────────────────────────────────

    public function test_detects_data_uri_as_binary(): void
    {
        $this->assertTrue(
            PayloadSanitizer::isBinaryContent('data:image/png;base64,iVBORw0KGgo='),
        );
    }

    public function test_detects_long_base64_string_as_binary(): void
    {
        // >256 chars, >92% base64 alphabet
        $this->assertTrue(
            PayloadSanitizer::isBinaryContent(str_repeat('A', 300)),
        );
    }

    public function test_does_not_detect_normal_text_as_binary(): void
    {
        $this->assertFalse(
            PayloadSanitizer::isBinaryContent('hello world this is plain text'),
        );
    }

    public function test_does_not_flag_short_strings(): void
    {
        // Short base64-looking string (<= 256 chars) — not flagged
        $this->assertFalse(
            PayloadSanitizer::isBinaryContent('dGVzdA=='),
        );
    }

    public function test_does_not_detect_normal_json_as_binary(): void
    {
        $this->assertFalse(
            PayloadSanitizer::isBinaryContent('{"name":"test","value":42}'),
        );
    }

    // ── sanitizeToJsonAttr() ─────────────────────────────────────────

    public function test_returns_null_for_null_payload(): void
    {
        $this->assertNull(PayloadSanitizer::sanitizeToJsonAttr(null));
    }

    public function test_returns_null_when_max_bytes_is_zero(): void
    {
        $this->assertNull(
            PayloadSanitizer::sanitizeToJsonAttr(['key' => 'value'], ['maxBytes' => 0]),
        );
    }

    public function test_returns_null_for_empty_array(): void
    {
        $this->assertNull(PayloadSanitizer::sanitizeToJsonAttr([]));
    }

    public function test_serializes_simple_array_to_json_string(): void
    {
        $result = PayloadSanitizer::sanitizeToJsonAttr(['name' => 'test', 'value' => 42]);
        $this->assertNotNull($result);
        $decoded = json_decode($result, true);
        $this->assertSame('test', $decoded['name']);
        $this->assertSame(42, $decoded['value']);
    }

    public function test_redacts_default_sensitive_fields(): void
    {
        $result = PayloadSanitizer::sanitizeToJsonAttr([
            'username' => 'alice',
            'password' => 'secret123',
        ]);
        $this->assertNotNull($result);
        $decoded = json_decode($result, true);
        $this->assertSame('[REDACTED]', $decoded['password']);
        $this->assertSame('alice', $decoded['username']);
    }

    public function test_redacts_custom_sensitive_fields(): void
    {
        $result = PayloadSanitizer::sanitizeToJsonAttr(
            ['name' => 'alice', 'ssn' => '123-45-6789'],
            ['sensitiveFields' => ['ssn']],
        );
        $this->assertNotNull($result);
        $decoded = json_decode($result, true);
        $this->assertSame('[REDACTED]', $decoded['ssn']);
        $this->assertSame('alice', $decoded['name']);
    }

    public function test_redacts_nested_sensitive_fields(): void
    {
        $result = PayloadSanitizer::sanitizeToJsonAttr([
            'user' => ['name' => 'bob', 'token' => 'abc123'],
        ]);
        $this->assertNotNull($result);
        $decoded = json_decode($result, true);
        $this->assertSame('[REDACTED]', $decoded['user']['token']);
        $this->assertSame('bob', $decoded['user']['name']);
    }

    public function test_truncates_at_max_bytes_with_indicator(): void
    {
        // Use chars outside base64 alphabet (spaces, !) to avoid binary detection heuristic
        $big = ['data' => str_repeat('hello world! ', 100)];
        $result = PayloadSanitizer::sanitizeToJsonAttr($big, ['maxBytes' => 50]);
        $this->assertNotNull($result);
        $this->assertStringContainsString('[truncated]', $result);
        $this->assertLessThanOrEqual(50 + strlen('...[truncated]'), strlen($result));
    }

    public function test_does_not_truncate_when_within_max_bytes(): void
    {
        $small = ['key' => 'value'];
        $result = PayloadSanitizer::sanitizeToJsonAttr($small, ['maxBytes' => 1024]);
        $this->assertNotNull($result);
        $this->assertStringNotContainsString('[truncated]', $result);
    }

    public function test_handles_nested_array_payload(): void
    {
        $result = PayloadSanitizer::sanitizeToJsonAttr([['id' => 1], ['id' => 2]]);
        $this->assertNotNull($result);
        $decoded = json_decode($result, true);
        $this->assertCount(2, $decoded);
    }

    public function test_case_insensitive_sensitive_field_matching(): void
    {
        $result = PayloadSanitizer::sanitizeToJsonAttr([
            'Password' => 'should-be-redacted',
            'TOKEN'    => 'also-redacted',
        ]);
        $this->assertNotNull($result);
        $decoded = json_decode($result, true);
        $this->assertSame('[REDACTED]', $decoded['Password']);
        $this->assertSame('[REDACTED]', $decoded['TOKEN']);
    }

    // ── sanitizeNested() ─────────────────────────────────────────────

    public function test_sanitize_nested_passes_scalar_through(): void
    {
        $result = PayloadSanitizer::sanitizeNested(42);
        $this->assertSame(42, $result);
    }

    public function test_sanitize_nested_handles_object(): void
    {
        $obj = new \stdClass();
        $obj->name  = 'alice';
        $obj->token = 'secret';

        $result = PayloadSanitizer::sanitizeNested($obj, ['token']);
        $this->assertIsArray($result);
        $this->assertSame('[REDACTED]', $result['token']);
        $this->assertSame('alice', $result['name']);
    }
}
