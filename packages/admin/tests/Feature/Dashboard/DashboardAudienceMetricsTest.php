<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildDashboardAnalyticsSnapshotAction;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Enums\ActivityBucketSubjectEnum;
use Capell\Core\Models\ActivityBucket;
use Capell\Core\Models\ActivityVisitor;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;

function recordVisitorDay(Site $site, string $hash, CarbonImmutable $at, string $language = 'en'): void
{
    ActivityVisitor::query()->create([
        'site_id' => $site->getKey(),
        'language' => $language,
        'day' => $at->toDateString(),
        'visitor_hash' => $hash,
        'first_seen_at' => $at,
    ]);
}

it('counts each visitor once per day and never twice within one day', function (): void {
    $site = Site::factory()->createOne();
    $now = CarbonImmutable::now('UTC');

    // The salt rotates daily, so one person browsing on three days is three
    // unlinkable hashes. Within a day the hash is stable, so several sessions
    // on the same day collapse to the single stored row.
    foreach ([1, 2, 3] as $daysAgo) {
        recordVisitorDay($site, str_repeat((string) $daysAgo, 32), $now->subDays($daysAgo));
    }

    recordVisitorDay($site, str_repeat('b', 32), $now->subDay());

    $actor = test()->createUserWithRole('super_admin');
    $snapshot = resolve(BuildDashboardAnalyticsSnapshotAction::class)->handle($actor, 'this_week');

    expect(ActivityVisitor::query()->count())->toBe(4)
        ->and($snapshot->visitors)->toBe(4);
});

it('scopes visitors to the sites an actor is assigned', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $now = CarbonImmutable::now('UTC')->subDay();

    recordVisitorDay($assignedSite, str_repeat('a', 32), $now);
    recordVisitorDay($otherSite, str_repeat('b', 32), $now);

    $actor = ScopedAdminUser::make(collect([$assignedSite->getKey()]));
    $action = resolve(BuildDashboardAnalyticsSnapshotAction::class);

    expect($action->handle($actor, 'this_week')->visitors)->toBe(1)
        ->and($action->handle($actor, 'this_week', $otherSite->getKey())->visitors)->toBe(0);
});

it('suppresses the comparison until a previous period exists', function (): void {
    $site = Site::factory()->createOne();
    $now = CarbonImmutable::now('UTC');

    recordVisitorDay($site, str_repeat('a', 32), $now->subDay());

    $actor = test()->createUserWithRole('super_admin');
    $fresh = resolve(BuildDashboardAnalyticsSnapshotAction::class)->handle($actor, 'this_week');

    expect($fresh->hasPreviousPeriod)->toBeFalse()
        ->and($fresh->visitorsChangePercent())->toBeNull();

    recordVisitorDay($site, str_repeat('b', 32), $now->subDays(9));
    recordVisitorDay($site, str_repeat('c', 32), $now->subDays(10));

    $compared = resolve(BuildDashboardAnalyticsSnapshotAction::class)->handle($actor, 'this_week');

    expect($compared->hasPreviousPeriod)->toBeTrue()
        ->and($compared->previousVisitors)->toBe(2)
        ->and($compared->visitorsChangePercent())->toBe(-50.0);
});

it('derives views per visitor and stays null without visitors', function (): void {
    $site = Site::factory()->createOne();
    $now = CarbonImmutable::now('UTC')->subDay();

    ActivityBucket::query()->create([
        'site_id' => $site->getKey(),
        'language' => 'en',
        'subject_type' => ActivityBucketSubjectEnum::PageView,
        'subject_key' => '1',
        'bucket_started_at' => $now,
        'count' => 5,
    ]);

    $actor = test()->createUserWithRole('super_admin');
    $action = resolve(BuildDashboardAnalyticsSnapshotAction::class);

    expect($action->handle($actor, 'this_week')->viewsPerVisitor())->toBeNull();

    recordVisitorDay($site, str_repeat('a', 32), $now);
    recordVisitorDay($site, str_repeat('b', 32), $now);

    expect($action->handle($actor, 'this_week')->viewsPerVisitor())->toBe(2.5);
});

it('shows the audience stats by default and hides the inventory counts', function (): void {
    $keys = collect(CapellAdmin::getOverviewStats())->pluck('key');
    $allKeys = collect(CapellAdmin::getOverviewStats(false))->pluck('key');

    expect($keys)->toContain('capell_overview.visitors')
        ->and($keys)->toContain('capell_overview.views_per_visitor')
        ->and($keys)->not->toContain('capell_overview.pages')
        ->and($keys)->not->toContain('capell_overview.sites')
        // Demoted, never removed: the registry keys still exist for extensions
        // that assert on them, and an operator can switch them back on.
        ->and($allKeys)->toContain('capell_overview.pages')
        ->and($allKeys)->toContain('capell_overview.sites')
        ->and($allKeys)->toContain('capell_overview.languages')
        ->and($allKeys)->toContain('capell_overview.page_types');
});
