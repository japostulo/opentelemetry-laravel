<?php

declare(strict_types=1);

namespace Haoc\OpenTelemetry\Tests\Unit\Core;

use Haoc\OpenTelemetry\Attributes\SemanticAttributes;
use PHPUnit\Framework\TestCase;

class SemanticAttributesTest extends TestCase
{
    // ── OTel Semconv v1.24+ ───────────────────────────────────────────

    public function test_http_request_method_value(): void
    {
        $this->assertSame('http.request.method', SemanticAttributes::HTTP_REQUEST_METHOD);
    }

    public function test_http_response_status_code_value(): void
    {
        $this->assertSame('http.response.status_code', SemanticAttributes::HTTP_RESPONSE_STATUS_CODE);
    }

    public function test_http_route_value(): void
    {
        $this->assertSame('http.route', SemanticAttributes::HTTP_ROUTE);
    }

    public function test_url_path_value(): void
    {
        $this->assertSame('url.path', SemanticAttributes::URL_PATH);
    }

    public function test_url_query_value(): void
    {
        $this->assertSame('url.query', SemanticAttributes::URL_QUERY);
    }

    public function test_url_full_value(): void
    {
        $this->assertSame('url.full', SemanticAttributes::URL_FULL);
    }

    public function test_user_agent_original_value(): void
    {
        $this->assertSame('user_agent.original', SemanticAttributes::USER_AGENT_ORIGINAL);
    }

    public function test_server_address_value(): void
    {
        $this->assertSame('server.address', SemanticAttributes::SERVER_ADDRESS);
    }

    public function test_server_port_value(): void
    {
        $this->assertSame('server.port', SemanticAttributes::SERVER_PORT);
    }

    public function test_network_protocol_version_value(): void
    {
        $this->assertSame('network.protocol.version', SemanticAttributes::NETWORK_PROTOCOL_VERSION);
    }

    // ── Legacy aliases ───────────────────────────────────────────────

    public function test_http_method_legacy_value(): void
    {
        $this->assertSame('http.method', SemanticAttributes::HTTP_METHOD_LEGACY);
    }

    public function test_http_status_code_legacy_value(): void
    {
        $this->assertSame('http.status_code', SemanticAttributes::HTTP_STATUS_CODE_LEGACY);
    }

    public function test_legacy_names_differ_from_current_semconv(): void
    {
        $this->assertNotSame(
            SemanticAttributes::HTTP_METHOD_LEGACY,
            SemanticAttributes::HTTP_REQUEST_METHOD,
        );
        $this->assertNotSame(
            SemanticAttributes::HTTP_STATUS_CODE_LEGACY,
            SemanticAttributes::HTTP_RESPONSE_STATUS_CODE,
        );
    }

    // ── HAOC institutional attributes ────────────────────────────────

    public function test_haoc_profile_value(): void
    {
        $this->assertSame('otel.profile', SemanticAttributes::HAOC_PROFILE);
    }

    public function test_haoc_is_preflight_value(): void
    {
        $this->assertSame('http.is_preflight', SemanticAttributes::HAOC_IS_PREFLIGHT);
    }

    public function test_haoc_log_event_value(): void
    {
        $this->assertSame('log.event', SemanticAttributes::HAOC_LOG_EVENT);
    }

    public function test_haoc_request_json_value(): void
    {
        $this->assertSame('request.json', SemanticAttributes::HAOC_REQUEST_JSON);
    }

    public function test_haoc_response_json_value(): void
    {
        $this->assertSame('response.json', SemanticAttributes::HAOC_RESPONSE_JSON);
    }

    public function test_haoc_error_json_value(): void
    {
        $this->assertSame('error.json', SemanticAttributes::HAOC_ERROR_JSON);
    }

    public function test_haoc_log_title_value(): void
    {
        $this->assertSame('log.title', SemanticAttributes::HAOC_LOG_TITLE);
    }

    // ── Log event values ─────────────────────────────────────────────

    public function test_log_event_request_value(): void
    {
        $this->assertSame('http.request', SemanticAttributes::LOG_EVENT_REQUEST);
    }

    public function test_log_event_response_value(): void
    {
        $this->assertSame('http.response', SemanticAttributes::LOG_EVENT_RESPONSE);
    }

    public function test_log_event_error_value(): void
    {
        $this->assertSame('http.error', SemanticAttributes::LOG_EVENT_ERROR);
    }

    public function test_log_event_preflight_value(): void
    {
        $this->assertSame('http.preflight', SemanticAttributes::LOG_EVENT_PREFLIGHT);
    }

    public function test_all_log_event_values_are_distinct(): void
    {
        $values = [
            SemanticAttributes::LOG_EVENT_REQUEST,
            SemanticAttributes::LOG_EVENT_RESPONSE,
            SemanticAttributes::LOG_EVENT_ERROR,
            SemanticAttributes::LOG_EVENT_PREFLIGHT,
        ];

        $this->assertSame(count($values), count(array_unique($values)));
    }
}
