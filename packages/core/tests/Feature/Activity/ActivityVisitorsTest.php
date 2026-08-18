<?php

declare(strict_types=1);

use Capell\Core\Actions\Activity\PruneActivityVisitorsAction;
use Capell\Core\Actions\Activity\RecordActivityVisitorAction;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Models\ActivityVisitor;
use Capell\Core\Models\MetricCollectionRun;
use Capell\Core\Models\Site;
use Capell\Core\Support\Metrics\ActivityVisitorsDailyMetricsCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

it('records one row per visitor per day and stores no identifiers', function (): void {
    $site = Site::factory()->createOne();
    $action = resolve(RecordActivityVisitorAction::class);
    $day = CarbonImmutable::parse('2026-08-18 09:00:00', 'UTC');

    expect($action->execute($site, 'en', '203.0.113.7', 'Mozilla/5.0', $day))->toBeTrue()
        ->and($action->execute($site, 'en', '203.0.113.7', 'Mozilla/5.0', $day->addHours(4)))->toBeFalse();

    $visitor = ActivityVisitor::query()->sole();

    expect(ActivityVisitor::query()->count())->toBe(1)
        ->and($visitor->visitor_hash)->not->toContain('203.0.113.7')
        ->and($visitor->day->toDateString())->toBe('2026-08-18')
        ->and(Schema::getColumnListing('activity_visitors'))
        ->not->toContain('ip')
        ->not->toContain('ip_address')
        ->not->toContain('user_agent')
        ->not->toContain('cookie')
        ->not->toContain('session_id')
        ->not->toContain('user_id');
});

it('writes exactly one row when the deduplication cache is cold', function (): void {
    $site = Site::factory()->createOne();
    $action = resolve(RecordActivityVisitorAction::class);
    $day = CarbonImmutable::parse('2026-08-18 09:00:00', 'UTC');

    $action->execute($site, 'en', '203.0.113.7', 'Mozilla/5.0', $day);
    Cache::flush();
    $action->execute($site, 'en', '203.0.113.7', 'Mozilla/5.0', $day->addHour());

    expect(ActivityVisitor::query()->count())->toBe(1);
});

it('rotates the visitor hash across UTC days and separates sites', function (): void {
    $site = Site::factory()->createOne();
    $other = Site::factory()->createOne();
    $action = resolve(RecordActivityVisitorAction::class);
    $siteId = (int) $site->getKey();

    $today = $action->hash($siteId, '203.0.113.7', 'Mozilla/5.0', '2026-08-18');
    $tomorrow = $action->hash($siteId, '203.0.113.7', 'Mozilla/5.0', '2026-08-19');
    $elsewhere = $action->hash((int) $other->getKey(), '203.0.113.7', 'Mozilla/5.0', '2026-08-18');

    expect($today)->not->toBe($tomorrow)
        ->and($today)->not->toBe($elsewhere)
        ->and($today)->toHaveLength(32);
});

it('makes a returning visitor unlinkable across days, counting them once per day', function (): void {
    $site = Site::factory()->createOne();
    $action = resolve(RecordActivityVisitorAction::class);

    foreach (['2026-08-16', '2026-08-17', '2026-08-18'] as $day) {
        Cache::flush();
        $action->execute($site, 'en', '203.0.113.7', 'Mozilla/5.0', CarbonImmutable::parse($day . ' 10:00:00', 'UTC'));
    }

    $rows = ActivityVisitor::query()->count();
    $unique = ActivityVisitor::query()->distinct()->count('visitor_hash');

    // The salt rotates every UTC day, so the same person hashes differently each
    // day and cannot be followed across them. The deliberate cost is that a
    // multi-day window counts a returning visitor once per day.
    expect($rows)->toBe(3)
        ->and($unique)->toBe(3);
});

it('collects unique visitors as a daily gauge that cannot be summed', function (): void {
    $site = Site::factory()->createOne();
    $action = resolve(RecordActivityVisitorAction::class);
    $day = CarbonImmutable::parse('2026-08-18 10:00:00', 'UTC');

    foreach (['203.0.113.7', '203.0.113.8'] as $ip) {
        Cache::flush();
        $action->execute($site, 'en', $ip, 'Mozilla/5.0', $day);
    }

    $collector = new ActivityVisitorsDailyMetricsCollector;
    $result = $collector->collect('2026-08-18', [MetricScopeData::siteLanguage($site->uuid, 'en', 'UTC')]);
    $definition = $collector->definitions()[0];

    expect($result->samples)->toHaveCount(1)
        ->and($result->samples[0]->value->integer)->toBe(2)
        ->and($definition->semantics->aggregation->value)->not->toBe('sum')
        ->and($definition->identity->collectorKey)->toBe('activity-visitors');
});

it('refuses to prune visitor days that have no completed rollup', function (): void {
    $site = Site::factory()->createOne();
    $expired = CarbonImmutable::now('UTC')->subDays(40);

    ActivityVisitor::query()->create([
        'site_id' => $site->getKey(),
        'language' => 'en',
        'day' => $expired->toDateString(),
        'visitor_hash' => str_repeat('a', 32),
        'first_seen_at' => $expired,
    ]);

    expect(fn (): int => resolve(PruneActivityVisitorsAction::class)->execute())
        ->toThrow(RuntimeException::class);

    MetricCollectionRun::query()->create([
        'owner_package' => ActivityVisitorsDailyMetricsCollector::OWNER_PACKAGE,
        'collector_key' => ActivityVisitorsDailyMetricsCollector::COLLECTOR_KEY,
        'day' => $expired->toDateString(),
        'definition_hash' => str_repeat('c', 64),
        'status' => MetricCollectionRunStatus::Completed,
        'source_watermark' => 'activity-visitor:empty',
        'source_checksum' => str_repeat('d', 64),
        'started_at' => $expired,
        'completed_at' => $expired,
    ]);

    expect(resolve(PruneActivityVisitorsAction::class)->execute())->toBe(1)
        ->and(ActivityVisitor::query()->count())->toBe(0);
});

it('keeps at least fourteen days of visitor rows so the previous period survives', function (): void {
    $site = Site::factory()->createOne();
    $recent = CarbonImmutable::now('UTC')->subDays(10);

    ActivityVisitor::query()->create([
        'site_id' => $site->getKey(),
        'language' => 'en',
        'day' => $recent->toDateString(),
        'visitor_hash' => str_repeat('b', 32),
        'first_seen_at' => $recent,
    ]);

    // Even asked for a one-day window, the action refuses to go below fourteen.
    expect(resolve(PruneActivityVisitorsAction::class)->execute(1))->toBe(0)
        ->and(ActivityVisitor::query()->count())->toBe(1);
});
