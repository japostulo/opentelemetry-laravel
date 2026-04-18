<?php

namespace Haoc\OpenTelemetry\Logging;

use Monolog\Logger;
use OpenTelemetry\API\Logs\LoggerInterface;

class OtelLogChannelFactory
{
    public function __invoke(array $config): Logger
    {
        $otelLogger = app(LoggerInterface::class);

        return new Logger('otlp', [
            new OtelHandler($otelLogger, $config['level'] ?? 'debug'),
        ]);
    }
}
