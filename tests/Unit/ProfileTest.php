<?php

declare(strict_types=1);

namespace Haoc\OpenTelemetry\Tests\Unit;

use Haoc\OpenTelemetry\Profile;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for {@see Profile}. No Laravel container required.
 */
final class ProfileTest extends TestCase
{
    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'profile'               => Profile::MINIMAL,
            'sample_ratio'          => null,
            'capture_request_body'  => null,
            'capture_response_body' => null,
            'ignore_routes'         => [],
            'log_destination'       => 'both',
            'environment'           => 'local',
        ], $overrides);
    }

    // ── Profile selection ────────────────────────────────────────────

    public function test_defaults_to_minimal(): void
    {
        $p = Profile::fromConfig($this->baseConfig());
        $this->assertSame(Profile::MINIMAL, $p->get('profile'));
        $this->assertFalse($p->get('capture_request_body'));
        $this->assertFalse($p->get('capture_response_body'));
        $this->assertSame('both', $p->get('log_destination'));
    }

    public function test_standard_profile_enables_body_capture(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'profile' => Profile::STANDARD,
        ]));
        $this->assertTrue($p->get('capture_request_body'));
        $this->assertTrue($p->get('capture_response_body'));
    }

    public function test_verbose_profile_has_no_default_ignore_routes(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'profile' => Profile::VERBOSE,
        ]));
        $this->assertSame([], $p->get('ignore_routes'));
    }

    public function test_unknown_profile_falls_back_to_minimal_baseline(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'profile' => 'bogus',
        ]));
        // The name is preserved, but the baseline applied is the minimal one.
        $this->assertSame('bogus', $p->get('profile'));
        $this->assertFalse($p->get('capture_request_body'));
        $this->assertNotEmpty($p->get('ignore_routes'));
    }

    // ── Sample ratio ─────────────────────────────────────────────────

    public function test_sample_ratio_defaults_to_one_in_local(): void
    {
        $p = Profile::fromConfig($this->baseConfig());
        $this->assertSame(1.0, $p->get('sample_ratio'));
    }

    public function test_sample_ratio_drops_to_02_in_production_for_minimal(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'environment' => 'production',
        ]));
        $this->assertEqualsWithDelta(0.2, $p->get('sample_ratio'), 1e-9);
    }

    public function test_sample_ratio_drops_to_02_in_production_for_standard(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'profile'     => Profile::STANDARD,
            'environment' => 'production',
        ]));
        $this->assertEqualsWithDelta(0.2, $p->get('sample_ratio'), 1e-9);
    }

    public function test_sample_ratio_stays_full_for_verbose_in_production(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'profile'     => Profile::VERBOSE,
            'environment' => 'production',
        ]));
        $this->assertSame(1.0, $p->get('sample_ratio'));
    }

    public function test_explicit_sample_ratio_beats_environment_drop(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'environment'  => 'production',
            'sample_ratio' => '0.7',
        ]));
        $this->assertEqualsWithDelta(0.7, $p->get('sample_ratio'), 1e-9);
    }

    public function test_sample_ratio_clamped_to_0_1(): void
    {
        $low = Profile::fromConfig($this->baseConfig(['sample_ratio' => -2]));
        $high = Profile::fromConfig($this->baseConfig(['sample_ratio' => 5]));
        $this->assertSame(0.0, $low->get('sample_ratio'));
        $this->assertSame(1.0, $high->get('sample_ratio'));
    }

    public function test_blank_string_sample_ratio_treated_as_unset(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'sample_ratio' => '',
            'environment'  => 'production',
        ]));
        // Treated as unset → production default = 0.2
        $this->assertEqualsWithDelta(0.2, $p->get('sample_ratio'), 1e-9);
    }

    // ── Body capture toggles ─────────────────────────────────────────

    public function test_capture_body_true_string_resolves_to_bool(): void
    {
        foreach (['true', '1', 'yes', 'on'] as $truthy) {
            $p = Profile::fromConfig($this->baseConfig([
                'capture_request_body' => $truthy,
            ]));
            $this->assertTrue(
                $p->get('capture_request_body'),
                "Expected '$truthy' to resolve to true",
            );
        }
    }

    public function test_capture_body_false_string_resolves_to_bool(): void
    {
        foreach (['false', '0', 'no', 'off'] as $falsy) {
            $p = Profile::fromConfig($this->baseConfig([
                'profile'              => Profile::STANDARD,
                'capture_request_body' => $falsy,
            ]));
            $this->assertFalse(
                $p->get('capture_request_body'),
                "Expected '$falsy' to resolve to false",
            );
        }
    }

    public function test_capture_body_invalid_value_falls_back_to_baseline(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'profile'              => Profile::STANDARD, // baseline = true
            'capture_request_body' => 'maybe',
        ]));
        $this->assertTrue($p->get('capture_request_body'));
    }

    public function test_capture_body_native_bool_passes_through(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'capture_request_body' => true,
        ]));
        $this->assertTrue($p->get('capture_request_body'));
    }

    // ── ignore_routes ────────────────────────────────────────────────

    public function test_ignore_routes_merges_baseline_with_user_patterns(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'ignore_routes' => ['^api/health$', 'admin/.*'],
        ]));
        $patterns = $p->get('ignore_routes');
        // Baseline provides 6 patterns (health, healthz, up, debugbar, telescope, horizon).
        $this->assertGreaterThanOrEqual(8, count($patterns));
        // User-supplied patterns must work via matchesAny.
        $this->assertTrue(Profile::matchesAny($patterns, 'api/health'));
        $this->assertTrue(Profile::matchesAny($patterns, 'admin/dashboard'));
    }

    public function test_ignore_routes_baseline_matches_well_known_paths(): void
    {
        $p = Profile::fromConfig($this->baseConfig());
        $patterns = $p->get('ignore_routes');
        foreach (['health', 'up', 'telescope/foo', 'horizon', '_debugbar/x'] as $route) {
            $this->assertTrue(
                Profile::matchesAny($patterns, $route),
                "expected baseline to ignore '$route'",
            );
        }
    }

    public function test_ignore_routes_blank_entries_skipped(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'ignore_routes' => ['', '  ', 'foo'],
        ]));
        $patterns = $p->get('ignore_routes');
        $userPatternCount = count($patterns) - 6; // baseline = 6
        $this->assertSame(1, $userPatternCount);
        $this->assertTrue(Profile::matchesAny($patterns, 'foo'));
    }

    // ── matchesAny edge cases ────────────────────────────────────────

    public function test_matches_any_returns_false_when_empty(): void
    {
        $this->assertFalse(Profile::matchesAny([], 'anything'));
    }

    public function test_matches_any_returns_false_when_no_pattern_hits(): void
    {
        $this->assertFalse(Profile::matchesAny(['/^never$/'], 'foo'));
    }

    public function test_matches_any_silently_skips_invalid_patterns(): void
    {
        // Garbage pattern triggers preg_match warning (suppressed by `@`)
        // and must not throw.
        $this->assertFalse(Profile::matchesAny(['['], 'foo'));
    }

    public function test_matches_any_is_case_insensitive_via_baseline_flag(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'ignore_routes' => ['^API/HEALTH$'],
        ]));
        $this->assertTrue(Profile::matchesAny($p->get('ignore_routes'), 'api/health'));
    }

    // ── log_destination passthrough ──────────────────────────────────

    public function test_log_destination_passthrough(): void
    {
        $p = Profile::fromConfig($this->baseConfig([
            'log_destination' => 'console',
        ]));
        $this->assertSame('console', $p->get('log_destination'));
    }

    public function test_log_destination_default_is_both_when_missing(): void
    {
        $cfg = $this->baseConfig();
        unset($cfg['log_destination']);
        $p = Profile::fromConfig($cfg);
        $this->assertSame('both', $p->get('log_destination'));
    }

    // ── get() default value ──────────────────────────────────────────

    public function test_get_returns_default_for_missing_key(): void
    {
        $p = Profile::fromConfig($this->baseConfig());
        $this->assertSame('fallback', $p->get('does_not_exist', 'fallback'));
        $this->assertNull($p->get('also_missing'));
    }
}
