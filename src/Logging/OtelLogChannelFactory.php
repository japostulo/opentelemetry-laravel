<?php

namespace Haoc\OpenTelemetry\Logging;

use Monolog\Logger;
use OpenTelemetry\API\Logs\LoggerInterface;

class OtelLogChannelFactory
{
    public function __invoke(array $config): Logger
    {
        $otelLogger = app(LoggerInterface::class);

        $destination = $config['destination']
            ?? config('haoc-otel.log_destination', 'both');
        // Emit via OTLP unless the consumer opted out.
        $emitToOtlp = !in_array($destination, ['console', 'none'], true);

        return new Logger('otlp', [
            new OtelHandler(
                $otelLogger,
                $config['level'] ?? 'debug',
                true,
                $emitToOtlp,
            ),
        ]);
    }
}
