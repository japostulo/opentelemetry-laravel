<?php

declare(strict_types=1);

namespace Haoc\OpenTelemetry\Tests\Unit\Core;

use Haoc\OpenTelemetry\Profile\ObservabilityProfile;
use PHPUnit\Framework\TestCase;

class ObservabilityProfileTest extends TestCase
{
    // ── getContract() ────────────────────────────────────────────────

    public function test_minimal_span_payload_mode_is_none(): void
    {
        $c = ObservabilityProfile::getContract('minimal');
        $this->assertSame(ObservabilityProfile::PAYLOAD_MODE_NONE, $c['spanPayloadMode']);
    }

    public function test_minimal_log_payload_mode_is_none(): void
    {
        $c = ObservabilityProfile::getContract('minimal');
        $this->assertSame(ObservabilityProfile::PAYLOAD_MODE_NONE, $c['logPayloadMode']);
    }

    public function test_minimal_preflight_log_is_false(): void
    {
        $c = ObservabilityProfile::getContract('minimal');
        $this->assertFalse($c['preflightLog']);
    }

    public function test_minimal_max_req_bytes_is_zero(): void
    {
        $c = ObservabilityProfile::getContract('minimal');
        $this->assertSame(0, $c['maxReqBytes']);
    }

    public function test_minimal_max_res_bytes_is_zero(): void
    {
        $c = ObservabilityProfile::getContract('minimal');
        $this->assertSame(0, $c['maxResBytes']);
    }

    public function test_standard_span_payload_mode_is_none(): void
    {
        $c = ObservabilityProfile::getContract('standard');
        $this->assertSame(ObservabilityProfile::PAYLOAD_MODE_NONE, $c['spanPayloadMode']);
    }

    public function test_standard_log_payload_mode_is_json_attr(): void
    {
        $c = ObservabilityProfile::getContract('standard');
        $this->assertSame(ObservabilityProfile::PAYLOAD_MODE_JSON_ATTR, $c['logPayloadMode']);
    }

    public function test_standard_preflight_log_is_false(): void
    {
        $c = ObservabilityProfile::getContract('standard');
        $this->assertFalse($c['preflightLog']);
    }

    public function test_standard_max_req_bytes_is_16kb(): void
    {
        $c = ObservabilityProfile::getContract('standard');
        $this->assertSame(16 * 1024, $c['maxReqBytes']);
    }

    public function test_verbose_span_payload_mode_is_flatten(): void
    {
        $c = ObservabilityProfile::getContract('verbose');
        $this->assertSame(ObservabilityProfile::PAYLOAD_MODE_FLATTEN, $c['spanPayloadMode']);
    }

    public function test_verbose_log_payload_mode_is_json_attr(): void
    {
        $c = ObservabilityProfile::getContract('verbose');
        $this->assertSame(ObservabilityProfile::PAYLOAD_MODE_JSON_ATTR, $c['logPayloadMode']);
    }

    public function test_verbose_preflight_log_is_true(): void
    {
        $c = ObservabilityProfile::getContract('verbose');
        $this->assertTrue($c['preflightLog']);
    }

    public function test_verbose_max_req_bytes_is_64kb(): void
    {
        $c = ObservabilityProfile::getContract('verbose');
        $this->assertSame(64 * 1024, $c['maxReqBytes']);
    }

    // ── Fallback for unknown profile ─────────────────────────────────

    public function test_unknown_profile_falls_back_to_minimal(): void
    {
        $c = ObservabilityProfile::getContract('nonexistent');
        $this->assertSame(ObservabilityProfile::PAYLOAD_MODE_NONE, $c['logPayloadMode']);
        $this->assertSame(0, $c['maxReqBytes']);
    }

    public function test_empty_string_falls_back_to_minimal(): void
    {
        $c = ObservabilityProfile::getContract('');
        $this->assertSame(ObservabilityProfile::PAYLOAD_MODE_NONE, $c['logPayloadMode']);
    }

    // ── profileNames() ───────────────────────────────────────────────

    public function test_profile_names_contains_all_three(): void
    {
        $names = ObservabilityProfile::profileNames();
        $this->assertContains('minimal', $names);
        $this->assertContains('standard', $names);
        $this->assertContains('verbose', $names);
    }

    // ── isValidPayloadMode() ─────────────────────────────────────────

    public function test_valid_payload_modes(): void
    {
        $this->assertTrue(ObservabilityProfile::isValidPayloadMode('none'));
        $this->assertTrue(ObservabilityProfile::isValidPayloadMode('json-attr'));
        $this->assertTrue(ObservabilityProfile::isValidPayloadMode('flatten'));
    }

    public function test_invalid_payload_mode(): void
    {
        $this->assertFalse(ObservabilityProfile::isValidPayloadMode('invalid'));
        $this->assertFalse(ObservabilityProfile::isValidPayloadMode(''));
    }
}
