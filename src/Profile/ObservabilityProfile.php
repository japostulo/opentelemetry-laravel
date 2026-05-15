<?php

declare(strict_types=1);

namespace Haoc\OpenTelemetry\Profile;

/**
 * Central contract for the three observability profiles.
 *
 * Mirror of packages/node/src/core/observability-profile.ts for PHP/Laravel.
 *
 * Profile × payload mode table:
 *
 * | Profile  | spanPayloadMode | logPayloadMode | preflightLog | maxReqBytes | maxResBytes |
 * |----------|-----------------|----------------|--------------|-------------|-------------|
 * | minimal  | none            | none           | false        | 0           | 0           |
 * | standard | none            | json-attr      | false        | 16 384      | 16 384      |
 * | verbose  | flatten         | json-attr      | true         | 65 536      | 65 536      |
 *
 * Payload mode definitions:
 * - 'none'      : payload is never captured or logged
 * - 'json-attr' : payload is JSON-serialised into a single span/log attribute
 * - 'flatten'   : payload is dot-notation flattened into multiple attributes
 */
final class ObservabilityProfile
{
    public const PAYLOAD_MODE_NONE      = 'none';
    public const PAYLOAD_MODE_JSON_ATTR = 'json-attr';
    public const PAYLOAD_MODE_FLATTEN   = 'flatten';

    private const VALID_MODES = [
        self::PAYLOAD_MODE_NONE,
        self::PAYLOAD_MODE_JSON_ATTR,
        self::PAYLOAD_MODE_FLATTEN,
    ];

    private const CONTRACTS = [
        'minimal' => [
            'spanPayloadMode' => self::PAYLOAD_MODE_NONE,
            'logPayloadMode'  => self::PAYLOAD_MODE_NONE,
            'preflightLog'    => false,
            'maxReqBytes'     => 0,
            'maxResBytes'     => 0,
        ],
        'standard' => [
            'spanPayloadMode' => self::PAYLOAD_MODE_NONE,
            'logPayloadMode'  => self::PAYLOAD_MODE_JSON_ATTR,
            'preflightLog'    => false,
            'maxReqBytes'     => 16 * 1024,
            'maxResBytes'     => 16 * 1024,
        ],
        'verbose' => [
            'spanPayloadMode' => self::PAYLOAD_MODE_FLATTEN,
            'logPayloadMode'  => self::PAYLOAD_MODE_JSON_ATTR,
            'preflightLog'    => true,
            'maxReqBytes'     => 64 * 1024,
            'maxResBytes'     => 64 * 1024,
        ],
    ];

    /**
     * Returns the ProfileContract array for the given profile name.
     * Falls back to 'minimal' for unknown names.
     *
     * @return array{spanPayloadMode: string, logPayloadMode: string, preflightLog: bool, maxReqBytes: int, maxResBytes: int}
     */
    public static function getContract(string $profileName): array
    {
        return self::CONTRACTS[$profileName] ?? self::CONTRACTS['minimal'];
    }

    /**
     * Returns all profile names.
     *
     * @return string[]
     */
    public static function profileNames(): array
    {
        return array_keys(self::CONTRACTS);
    }

    /**
     * Validates a payload mode string.
     */
    public static function isValidPayloadMode(string $mode): bool
    {
        return in_array($mode, self::VALID_MODES, true);
    }

    /** @codeCoverageIgnore */
    private function __construct() {}
}
