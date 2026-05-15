<?php

namespace Haoc\OpenTelemetry\Logging;

use Monolog\Logger;
use OpenTelemetry\API\Logs\LoggerInterface;

class OtelLogChannelFactory
{
    public function __invoke(array $config): Logger
    {
        $otelLogger = app(LoggerInterface::class);

        // When destination is provided explicitly in the channel config
        // (e.g. config/logging.php), it takes precedence over the runtime
        // value. Otherwise leave the handler in dynamic mode (null) so it
        // re-reads `haoc-otel.log_destination` on every write.
        $explicitDestination = $config['destination'] ?? null;
        $emitToOtlp = $explicitDestination === null
            ? null
            : !in_array($explicitDestination, ['console', 'none'], true);

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
