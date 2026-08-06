<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildDashboardAnalyticsSnapshotAction;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Enums\ActivityBucketSubjectEnum;
use Capell\Core\Models\ActivityBucket;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;

it('limits a site-scoped dashboard snapshot to assigned sites', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $bucketTime = CarbonImmutable::now('UTC')->subMinutes(5);

    ActivityBucket::query()->create([
        'site_id' => $assignedSite->getKey(),
        'language' => 'en',
        'subject_type' => ActivityBucketSubjectEnum::PageView,
        'subject_key' => '1',
        'bucket_started_at' => $bucketTime,
        'count' => 3,
    ]);
    ActivityBucket::query()->create([
        'site_id' => $otherSite->getKey(),
        'language' => 'en',
        'subject_type' => ActivityBucketSubjectEnum::PageView,
        'subject_key' => '2',
        'bucket_started_at' => $bucketTime,
        'count' => 7,
    ]);

    $actor = ScopedAdminUser::make(collect([$assignedSite->getKey()]));
    $action = resolve(BuildDashboardAnalyticsSnapshotAction::class);

    expect($action->execute($actor, 'today')->totalViews)->toBe(3)
        ->and($action->execute($actor, 'today', $otherSite->getKey())->totalViews)->toBe(0);
});

it('allows a global administrator to see the all-sites dashboard snapshot', function (): void {
    $firstSite = Site::factory()->createOne();
    $secondSite = Site::factory()->createOne();
    $bucketTime = CarbonImmutable::now('UTC')->subMinutes(5);

    foreach ([$firstSite, $secondSite] as $index => $site) {
        ActivityBucket::query()->create([
            'site_id' => $site->getKey(),
            'language' => 'en',
            'subject_type' => ActivityBucketSubjectEnum::PageView,
            'subject_key' => (string) ($index + 1),
            'bucket_started_at' => $bucketTime,
            'count' => 2,
        ]);
    }

    $actor = test()->createUserWithRole('super_admin');

    expect(resolve(BuildDashboardAnalyticsSnapshotAction::class)->execute($actor, 'today')->totalViews)->toBe(4);
});
