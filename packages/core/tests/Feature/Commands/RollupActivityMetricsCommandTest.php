<?php

declare(strict_types=1);

use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Models\MetricCollectionRun;
use Capell\Core\Models\MetricDailyRollup;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;

it('does not roll up activity buckets when collection is disabled', function (): void {
    $settings = Mockery::mock(ActivitySettingsReader::class);
    $settings->expects('collectionEnabled')->andReturnFalse();

    app()->instance(ActivitySettingsReader::class, $settings);

    artisanCommand('capell:activity:rollup')->assertSuccessful();
});

it('registers the activity collector and rolls up every language of enabled sites', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $language = $site->language;
    $settings = Mockery::mock(ActivitySettingsReader::class);
    $settings->expects('collectionEnabled')->andReturnTrue();

    app()->instance(ActivitySettingsReader::class, $settings);

    artisanCommand('capell:activity:rollup', ['--day' => '2026-08-12'])->assertSuccessful();

    // Page views, searches and unique visitors: the visitor collector is a
    // sibling registered by the same command.
    expect(MetricDailyRollup::query()->whereDate('day', '2026-08-12')->count())->toBe(3)
        ->and(MetricDailyRollup::query()->where('site_uuid', $site->uuid)->where('language', $language->code)->count())->toBe(3)
        ->and(MetricDailyRollup::query()->where('metric_key', 'unique_visitors')->exists())->toBeTrue();
});

it('uses the previous UTC day when no rollup day is supplied', function (): void {
    CarbonImmutable::setTestNow('2026-08-13 04:00:00 UTC');
    $settings = Mockery::mock(ActivitySettingsReader::class);
    $settings->expects('collectionEnabled')->andReturnTrue();

    app()->instance(ActivitySettingsReader::class, $settings);

    artisanCommand('capell:activity:rollup')->assertSuccessful();

    expect(MetricCollectionRun::query()
        ->whereDate('day', '2026-08-12')
        ->where('status', MetricCollectionRunStatus::Unsupported)
        ->exists())->toBeTrue();
});

it('returns failure when the rollup action throws', function (): void {
    $settings = Mockery::mock(ActivitySettingsReader::class);
    $settings->expects('collectionEnabled')->andReturnTrue();

    app()->instance(ActivitySettingsReader::class, $settings);

    artisanCommand('capell:activity:rollup', ['--day' => 'not-a-date'])->assertFailed();
});
