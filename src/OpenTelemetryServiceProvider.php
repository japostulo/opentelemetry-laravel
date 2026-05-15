<?php

namespace Haoc\OpenTelemetry;

use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/otel.php', 'otel');

        // ── Profile (resolved per-resolution so runtime config changes
        //    via Config::set() take effect on the very next request — the
        //    TraceRequest middleware re-resolves Profile on every handle()
        //    because the container builds the middleware fresh per
        //    request).
        $this->app->bind(Profile::class, function () {
            return Profile::fromConfig(config('otel'));
        });

        // NOTE: the active profile is NOT included as a resource attribute.
        // It can change at runtime via Config::set(); Resource attrs are
        // immutable post-init. The profile attribute is applied per-span
        // by `TraceRequest` middleware and per-log by `OtelHandler::write()`.
        $this->app->singleton('otel.resource', function ($app) {
            $config = config('otel');

            return ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => $config['service_name'],
                'deployment.environment' => $config['environment'],
                'service.version' => config('app.version', '0.0.0'),
            ]));
        });

        // ── Trace Provider (Batch + ParentBased(TraceIdRatio)) ───────────
        $this->app->singleton(TracerProviderInterface::class, function ($app) {
            $endpoint = config('otel.endpoint');
            $profile  = $app->make(Profile::class);

            $transport = (new OtlpHttpTransportFactory())->create(
                $endpoint . '/v1/traces',
                ContentTypes::PROTOBUF,
            );

            $processor = new BatchSpanProcessor(
                new SpanExporter($transport),
                ClockFactory::getDefault(),
            );

            $sampler = new ParentBased(
                new TraceIdRatioBasedSampler((float) $profile->get('sample_ratio', 1.0)),
            );

            return TracerProvider::builder()
                ->setResource($app->make('otel.resource'))
                ->addSpanProcessor($processor)
                ->setSampler($sampler)
                ->build();
        });

        $this->app->singleton(TracerInterface::class, function ($app) {
            return $app->make(TracerProviderInterface::class)
                ->getTracer(config('otel.service_name'));
        });

        // ── Log Provider (Batch) ───────────────────────────────────
        $this->app->singleton(LoggerProvider::class, function ($app) {
            $endpoint = config('otel.endpoint');

            $transport = (new OtlpHttpTransportFactory())->create(
                $endpoint . '/v1/logs',
                ContentTypes::PROTOBUF,
            );

            $processor = new BatchLogRecordProcessor(
                new LogsExporter($transport),
                ClockFactory::getDefault(),
            );

            return LoggerProvider::builder()
                ->setResource($app->make('otel.resource'))
                ->addLogRecordProcessor($processor)
                ->build();
        });

        $this->app->singleton(LoggerInterface::class, function ($app) {
            return $app->make(LoggerProvider::class)
                ->getLogger(config('otel.service_name'));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/otel.php' => config_path('otel.php'),
        ], 'otel-config');

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
