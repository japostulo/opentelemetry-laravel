<?php

declare(strict_types=1);

namespace Haoc\OpenTelemetry\Console;

use Illuminate\Console\Command;

class OtelDoctorCommand extends Command
{
    protected $signature = 'otel:doctor';
    protected $description = 'Validate HAOC OpenTelemetry configuration.';

    public function handle(): int
    {
        $errors = [];
        $warnings = [];

        $serviceName = (string) config('otel.service_name', '');
        $endpoint = (string) config('otel.endpoint', '');
        $profile = (string) config('otel.profile', 'minimal');
        $userIdMode = (string) config('otel.identity.user_id_mode', 'raw');
        $publicIpMode = (string) config('otel.privacy.public_ip_mode', 'raw');

        if ($serviceName === '') $errors[] = 'otel.service_name / OTEL_SERVICE_NAME is required.';
        if ($endpoint === '') $warnings[] = 'otel.endpoint / OTEL_EXPORTER_OTLP_ENDPOINT is empty.';
        if (!in_array($profile, ['minimal', 'standard', 'verbose'], true)) $warnings[] = "Unknown profile '{$profile}' falls back to minimal behaviour.";
        if (!in_array($userIdMode, ['raw', 'hash', 'off'], true)) $errors[] = "Invalid identity.user_id_mode '{$userIdMode}'.";
        if (!in_array($publicIpMode, ['raw', 'hash', 'off'], true)) $errors[] = "Invalid privacy.public_ip_mode '{$publicIpMode}'.";
        if (($userIdMode === 'hash' || $publicIpMode === 'hash') && (string) config('otel.identity.hash_salt', '') === '' && (string) config('otel.privacy.hash_salt', '') === '') {
            $warnings[] = 'Hash mode is enabled without HAOC_OTEL_HASH_SALT/config hash_salt.';
        }

        $this->line('HAOC OpenTelemetry configuration');
        $this->line("service_name: {$serviceName}");
        $this->line("endpoint: {$endpoint}");
        $this->line("profile: {$profile}");
        $this->line("identity.user_id_mode: {$userIdMode}");
        $this->line("privacy.public_ip_mode: {$publicIpMode}");

        foreach ($warnings as $warning) $this->warn($warning);
        foreach ($errors as $error) $this->error($error);

        return empty($errors) ? self::SUCCESS : self::FAILURE;
    }
}
