<?php

namespace Haoc\OpenTelemetry\Logging;

use Haoc\OpenTelemetry\Attributes\SemanticAttributes;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord as OtelLogRecord;
use OpenTelemetry\API\Logs\Severity;

class OtelHandler extends AbstractProcessingHandler
{
    private const MONOLOG_TO_OTEL = [
        Level::Debug->value     => Severity::DEBUG,
        Level::Info->value      => Severity::INFO,
        Level::Notice->value    => Severity::INFO2,
        Level::Warning->value   => Severity::WARN,
        Level::Error->value     => Severity::ERROR,
        Level::Critical->value  => Severity::FATAL,
        Level::Alert->value     => Severity::FATAL2,
        Level::Emergency->value => Severity::FATAL4,
    ];

    /**
     * @param bool|null $emitToOtlp  When `null`, emission is decided at
     *                                runtime by reading
     *                                `config('otel.log_destination')`.
     *                                When `true`/`false`, this overrides
     *                                the runtime config (mainly for tests).
     */
    public function __construct(
        private readonly LoggerInterface $otelLogger,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        private readonly ?bool $emitToOtlp = null,
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * Re-evaluated on every write so `/admin/config` can flip the log
     * destination at runtime without re-creating the handler / Monolog
     * channel / LoggerProvider.
     */
    private function shouldEmit(): bool
    {
        if ($this->emitToOtlp !== null) {
            return $this->emitToOtlp;
        }
        $destination = config('otel.log_destination', 'both');
        return !in_array($destination, ['console', 'none'], true);
    }

    protected function write(LogRecord $record): void
    {
        if (!$this->shouldEmit()) {
            return;
        }

        $severity = self::MONOLOG_TO_OTEL[$record->level->value] ?? Severity::INFO;

        // Body is emitted as structured JSON so SigNoz activates the tree view:
        //  - request log  → decoded haoc.request.json payload
        //  - response log → decoded haoc.response.json payload
        //  - other logs   → {"msg":"..."} fallback
        // The clean one-line title is stored in haoc.log.title (configure it
        // as the display column in SigNoz instead of body).
        $otelRecord = (new OtelLogRecord($this->buildBody($record)))
            ->setTimestamp((int) ($record->datetime->format('U.u') * 1_000_000_000))
            ->setSeverityNumber($severity)
            ->setSeverityText($record->level->name);

        $attributes = [
            // Stamped per-record so runtime config changes reflect immediately
            // (Resource attrs are immutable post-init).
            'otel.profile' => (string) config('otel.profile', 'minimal'),
        ];

        // Auto-populate log.title from the message when not explicitly set in
        // context, so every app-level Log::info/debug/warn call is searchable
        // by title in SigNoz without requiring callers to set it manually.
        if (!isset($record->context[SemanticAttributes::LOG_TITLE])) {
            $attributes[SemanticAttributes::LOG_TITLE] = $record->message;
        }

        // Keys already consumed as the log body — skip to avoid duplicate
        // string attribute alongside the structured body.
        $bodyKeys = [SemanticAttributes::REQUEST_JSON, SemanticAttributes::RESPONSE_JSON];
        foreach ($record->context as $key => $value) {
            if (in_array($key, $bodyKeys, true)) {
                continue;
            }
            if (is_scalar($value)) {
                $attributes[$key] = $value;
            } elseif (is_array($value)) {
                foreach ($this->flattenArray($key, $value) as $fk => $fv) {
                    $attributes[$fk] = $fv;
                }
            }
        }

        if (!empty($attributes)) {
            $otelRecord->setAttributes($attributes);
        }

        $this->otelLogger->emit($otelRecord);
    }

    /**
     * Build the body for the OTel log record.
     *
     * When the log context contains a request or response payload
     * (haoc.request.json / haoc.response.json), the decoded array is returned
     * directly so the OTel SDK serialises it as kvlist_value — which SigNoz
     * renders as the JSON tree view in the detail panel.
     *
     * For logs without a payload (minimal profile, errors, etc.) the body
     * falls back to the plain message string.
     *
     * @return array<string, mixed>|string
     */
    protected function buildBody(LogRecord $record): array|string
    {
        foreach ([
            SemanticAttributes::REQUEST_JSON,
            SemanticAttributes::RESPONSE_JSON,
        ] as $key) {
            $value = $record->context[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return $record->message;
    }

    private function flattenArray(string $prefix, array $data, int $depth = 0): array
    {
        if ($depth > 3) {
            return [];
        }

        $result = [];
        foreach ($data as $key => $value) {
            $attrKey = "{$prefix}.{$key}";
            if (is_scalar($value)) {
                $result[$attrKey] = (string) $value;
            } elseif (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($attrKey, $value, $depth + 1));
            }
        }
        return $result;
    }
}
