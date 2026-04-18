<?php

namespace Haoc\OpenTelemetry;

use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;

class HaocOpenTelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/haoc-otel.php', 'haoc-otel');

        $this->app->singleton('otel.resource', function () {
            $config = config('haoc-otel');

            return ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => $config['service_name'],
                'deployment.environment' => $config['environment'],
                'service.version' => config('app.version', '0.0.0'),
            ]));
        });

        // ── Trace Provider ──────────────────────────────────────────────
        $this->app->singleton(TracerProviderInterface::class, function ($app) {
            $endpoint = config('haoc-otel.endpoint');

            $transport = (new OtlpHttpTransportFactory())->create(
                $endpoint . '/v1/traces',
                ContentTypes::PROTOBUF,
            );

            return TracerProvider::builder()
                ->setResource($app->make('otel.resource'))
                ->addSpanProcessor(new SimpleSpanProcessor(new SpanExporter($transport)))
                ->build();
        });

        $this->app->singleton(TracerInterface::class, function ($app) {
            return $app->make(TracerProviderInterface::class)
                ->getTracer(config('haoc-otel.service_name'));
        });

        // ── Log Provider ────────────────────────────────────────────────
        $this->app->singleton(LoggerProvider::class, function ($app) {
            $endpoint = config('haoc-otel.endpoint');

            $transport = (new OtlpHttpTransportFactory())->create(
                $endpoint . '/v1/logs',
                ContentTypes::PROTOBUF,
            );

            return LoggerProvider::builder()
                ->setResource($app->make('otel.resource'))
                ->addLogRecordProcessor(new SimpleLogRecordProcessor(new LogsExporter($transport)))
                ->build();
        });

        $this->app->singleton(LoggerInterface::class, function ($app) {
            return $app->make(LoggerProvider::class)
                ->getLogger(config('haoc-otel.service_name'));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/haoc-otel.php' => config_path('haoc-otel.php'),
        ], 'haoc-otel-config');

        $this->app->terminating(function () {
            $traceProvider = $this->app->make(TracerProviderInterface::class);
            if ($traceProvider instanceof TracerProvider) {
                $traceProvider->shutdown();
            }

            $logProvider = $this->app->make(LoggerProvider::class);
            $logProvider->shutdown();
        });
    }
}
