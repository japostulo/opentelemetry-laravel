<?php

namespace Haoc\OpenTelemetry\Logging;

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

    public function __construct(
        private readonly LoggerInterface $otelLogger,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        private readonly bool $emitToOtlp = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (!$this->emitToOtlp) {
            return;
        }

        $severity = self::MONOLOG_TO_OTEL[$record->level->value] ?? Severity::INFO;

        $otelRecord = (new OtelLogRecord($record->message))
            ->setTimestamp((int) ($record->datetime->format('U.u') * 1_000_000_000))
            ->setSeverityNumber($severity)
            ->setSeverityText($record->level->name);

        $attributes = [];
        foreach ($record->context as $key => $value) {
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
