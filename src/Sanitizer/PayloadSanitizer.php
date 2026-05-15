<?php

declare(strict_types=1);

namespace Haoc\OpenTelemetry\Sanitizer;

/**
 * Unified payload sanitizer for HAOC OpenTelemetry instrumentation.
 *
 * Provides the same sanitization contract as the Node.js counterpart
 * (packages/node/src/core/sanitize-payload.ts):
 *   1. Redact sensitive fields
 *   2. JSON-serialize to string
 *   3. Truncate at maxBytes
 *   4. Detect binary / base64 content
 *
 * Returns null when the payload is empty, binary, exceeds maxBytes=0,
 * or serialisation fails.
 */
final class PayloadSanitizer
{
    /** Default list of sensitive field names to redact. */
    public const DEFAULT_SENSITIVE_FIELDS = [
        'password',
        'senha',
        'token',
        'secret',
        'authorization',
        'creditcard',
        'credit_card',
        'cvv',
        'ssn',
        'cpf',
        'rg',
        'passphrase',
        'private_key',
        'privatekey',
    ];

    private const REDACTED = '[REDACTED]';

    /**
     * Detect whether a string value looks like binary or base64-encoded data.
     *
     * Strategy (mirrors Node.js implementation):
     * - Data URI pattern: "data:<mime>;base64,..."
     * - Heuristic: strings longer than 256 chars with >92% base64 characters
     */
    public static function isBinaryContent(string $value): bool
    {
        if (str_starts_with($value, 'data:') && str_contains($value, ';base64,')) {
            return true;
        }

        if (strlen($value) > 256) {
            $base64Chars = preg_match_all('/[A-Za-z0-9+\/=]/', $value);
            if ($base64Chars !== false && ($base64Chars / strlen($value)) > 0.92) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize payload and return a JSON string attribute, or null.
     *
     * Returns null when:
     * - $payload is null
     * - maxBytes === 0
     * - Sanitised result is empty ({}, [], null)
     * - Serialisation fails
     * - Content is binary
     *
     * @param mixed $payload
     * @param array{
     *   sensitiveFields?: string[],
     *   maxBytes?: int,
     * } $options
     */
    public static function sanitizeToJsonAttr(mixed $payload, array $options = []): ?string
    {
        if ($payload === null) {
            return null;
        }

        $maxBytes       = $options['maxBytes'] ?? (64 * 1024);
        $sensitiveFields = $options['sensitiveFields'] ?? self::DEFAULT_SENSITIVE_FIELDS;

        if ($maxBytes === 0) {
            return null;
        }

        // Sanitize
        $sanitized = self::sanitizeNested($payload, $sensitiveFields);

        // Detect empty result
        if ($sanitized === null || $sanitized === [] || $sanitized === '') {
            return null;
        }
        if (is_array($sanitized) && count($sanitized) === 0) {
            return null;
        }

        // Serialize
        $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || $json === 'null' || $json === '{}' || $json === '[]') {
            return null;
        }

        // Binary detection on resulting JSON string
        if (self::isBinaryContent($json)) {
            return null;
        }

        // Truncate at maxBytes (UTF-8 byte length)
        if (strlen($json) > $maxBytes) {
            // Truncate by bytes, ensuring valid UTF-8
            $truncated = mb_strcut($json, 0, $maxBytes, 'UTF-8');
            // Append indicator
            return $truncated . '...[truncated]';
        }

        return $json;
    }

    /**
     * Recursively sanitize an array or object, redacting sensitive fields.
     *
     * @param mixed    $data
     * @param string[] $sensitiveFields
     * @return mixed
     */
    public static function sanitizeNested(mixed $data, array $sensitiveFields = []): mixed
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && self::isSensitiveField($key, $sensitiveFields)) {
                    $result[$key] = self::REDACTED;
                } else {
                    $result[$key] = self::sanitizeNested($value, $sensitiveFields);
                }
            }
            return $result;
        }

        if (is_object($data)) {
            $vars   = get_object_vars($data);
            $result = [];
            foreach ($vars as $key => $value) {
                if (self::isSensitiveField($key, $sensitiveFields)) {
                    $result[$key] = self::REDACTED;
                } else {
                    $result[$key] = self::sanitizeNested($value, $sensitiveFields);
                }
            }
            return $result;
        }

        if (is_string($data) && self::isBinaryContent($data)) {
            return '[BINARY]';
        }

        return $data;
    }

    /**
     * Case-insensitive sensitive field check.
     *
     * @param string[] $sensitiveFields
     */
    private static function isSensitiveField(string $key, array $sensitiveFields): bool
    {
        $keyLower = strtolower($key);
        foreach ($sensitiveFields as $field) {
            if (strtolower($field) === $keyLower) {
                return true;
            }
        }
        return false;
    }

    /** @codeCoverageIgnore */
    private function __construct() {}
}
