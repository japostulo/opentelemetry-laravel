<?php

declare(strict_types=1);

namespace Haoc\OpenTelemetry\Payload;

use Haoc\OpenTelemetry\Profile\ObservabilityProfile;

/**
 * Resolves the effective payload policy for a given profile, combining
 * profile defaults with environment-variable overrides and programmatic
 * overrides.
 *
 * Precedence (highest wins):
 *   1. Programmatic overrides (constructor $overrides array)
 *   2. Environment variable overrides
 *   3. Profile defaults from ObservabilityProfile
 *
 * Mirror of packages/node/src/core/payload-policy.ts for PHP/Laravel.
 */
final class PayloadPolicy
{
    public const DEFAULT_MAX_ATTRIBUTE_BYTES = 64 * 1024;

    private string $spanPayloadMode;
    private string $logPayloadMode;
    private int $maxRequestBytes;
    private int $maxResponseBytes;
    private int $maxAttributeBytes;

    /**
     * @param string  $profileName Profile name: minimal | standard | verbose
     * @param array{
     *   spanPayloadMode?: string,
     *   logPayloadMode?: string,
     *   maxRequestBytes?: int,
     *   maxResponseBytes?: int,
     *   maxAttributeBytes?: int,
     * } $overrides Optional programmatic overrides (highest precedence)
     */
    public function __construct(string $profileName = 'standard', array $overrides = [])
    {
        $contract = ObservabilityProfile::getContract($profileName);

        // ── 1. Start with profile defaults ────────────────────────────
        $spanMode      = $contract['spanPayloadMode'];
        $logMode       = $contract['logPayloadMode'];
        $maxReqBytes   = $contract['maxReqBytes'];
        $maxResBytes   = $contract['maxResBytes'];
        $maxAttrBytes  = self::DEFAULT_MAX_ATTRIBUTE_BYTES;

        // ── 2. Environment variable overrides ─────────────────────────
        $envReqBytes = getenv('OTEL_MAX_REQUEST_BODY_BYTES');
        if ($envReqBytes !== false && is_numeric($envReqBytes)) {
            $maxReqBytes = (int) $envReqBytes;
        }

        $envResBytes = getenv('OTEL_MAX_RESPONSE_BODY_BYTES');
        if ($envResBytes !== false && is_numeric($envResBytes)) {
            $maxResBytes = (int) $envResBytes;
        }

        $envAttrBytes = getenv('OTEL_MAX_ATTRIBUTE_VALUE_BYTES');
        if ($envAttrBytes !== false && is_numeric($envAttrBytes)) {
            $maxAttrBytes = (int) $envAttrBytes;
        }

        $envLogMode = getenv('OTEL_LOG_PAYLOAD_MODE');
        if ($envLogMode !== false && ObservabilityProfile::isValidPayloadMode($envLogMode)) {
            $logMode = $envLogMode;
        }

        // ── 3. Programmatic overrides (highest precedence) ─────────────
        if (isset($overrides['spanPayloadMode']) && ObservabilityProfile::isValidPayloadMode($overrides['spanPayloadMode'])) {
            $spanMode = $overrides['spanPayloadMode'];
        }
        if (isset($overrides['logPayloadMode']) && ObservabilityProfile::isValidPayloadMode($overrides['logPayloadMode'])) {
            $logMode = $overrides['logPayloadMode'];
        }
        if (isset($overrides['maxRequestBytes']) && is_int($overrides['maxRequestBytes'])) {
            $maxReqBytes = $overrides['maxRequestBytes'];
        }
        if (isset($overrides['maxResponseBytes']) && is_int($overrides['maxResponseBytes'])) {
            $maxResBytes = $overrides['maxResponseBytes'];
        }
        if (isset($overrides['maxAttributeBytes']) && is_int($overrides['maxAttributeBytes'])) {
            $maxAttrBytes = $overrides['maxAttributeBytes'];
        }

        $this->spanPayloadMode  = $spanMode;
        $this->logPayloadMode   = $logMode;
        $this->maxRequestBytes  = $maxReqBytes;
        $this->maxResponseBytes = $maxResBytes;
        $this->maxAttributeBytes = $maxAttrBytes;
    }

    public function getSpanPayloadMode(): string
    {
        return $this->spanPayloadMode;
    }

    public function getLogPayloadMode(): string
    {
        return $this->logPayloadMode;
    }

    public function getMaxRequestBytes(): int
    {
        return $this->maxRequestBytes;
    }

    public function getMaxResponseBytes(): int
    {
        return $this->maxResponseBytes;
    }

    public function getMaxAttributeBytes(): int
    {
        return $this->maxAttributeBytes;
    }

    /**
     * Returns true if the span payload should be captured (not 'none').
     */
    public function shouldCaptureSpanPayload(): bool
    {
        return $this->spanPayloadMode !== ObservabilityProfile::PAYLOAD_MODE_NONE;
    }

    /**
     * Returns true if the log payload should be captured (not 'none').
     */
    public function shouldCaptureLogPayload(): bool
    {
        return $this->logPayloadMode !== ObservabilityProfile::PAYLOAD_MODE_NONE;
    }
}
