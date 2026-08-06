<?php

declare(strict_types=1);

use Capell\Core\Actions\Activity\RecordActivityBucketAction;
use Capell\Core\Actions\Activity\RecordSearchActivityAction;
use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Core\Enums\ActivityBucketSubjectEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\ActivityBucket;
use Capell\Core\Models\Site;
use Capell\Core\Support\Activity\DefaultActivitySettingsReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

it('floors activity writes to canonical UTC five-minute buckets and increments atomically', function (): void {
    $site = Site::factory()->createOne();
    $action = resolve(RecordActivityBucketAction::class);

    $action->execute($site, 'en', ActivityBucketSubjectEnum::PageView, '42', CarbonImmutable::parse('2026-08-06 12:04:59 Europe/London'));
    $action->execute($site, 'en', ActivityBucketSubjectEnum::PageView, '42', CarbonImmutable::parse('2026-08-06 12:04:01 Europe/London'));

    $bucket = ActivityBucket::query()->sole();

    expect($bucket->bucket_started_at->toIso8601String())->toBe('2026-08-06T11:00:00+00:00')
        ->and($bucket->count)->toBe(2)
        ->and($bucket->subject_type)->toBe(ActivityBucketSubjectEnum::PageView);
});

it('normalizes search terms and rejects obvious sensitive values', function (): void {
    expect(RecordSearchActivityAction::normalize('  Capell   CMS '))->toBe('capell cms')
        ->and(RecordSearchActivityAction::normalize('person@example.com'))->toBeNull()
        ->and(RecordSearchActivityAction::normalize('https://example.com/search'))->toBeNull()
        ->and(RecordSearchActivityAction::normalize('+44 20 1234 5678'))->toBeNull();
});

it('keeps search collection disabled by default and stores no visitor identifiers', function (): void {
    $site = Site::factory()->createOne();
    $settings = new DefaultActivitySettingsReader;
    app()->instance(ActivitySettingsReader::class, $settings);
    config(['capell.analytics.search_collection_enabled' => false]);

    expect(resolve(RecordSearchActivityAction::class)->execute($site, 'en', 'pricing'))->toBeFalse()
        ->and(ActivityBucket::query()->count())->toBe(0)
        ->and(Schema::getColumnListing('activity_buckets'))
        ->not->toContain('ip')
        ->not->toContain('cookie')
        ->not->toContain('session_id')
        ->not->toContain('user_id')
        ->not->toContain('user_agent');
});

it('registers and protects activity storage', function (): void {
    expect(Schema::hasColumns('activity_buckets', [
        'site_id', 'language', 'subject_type', 'subject_key', 'bucket_started_at', 'count',
    ]))->toBeTrue()
        ->and(CapellCore::getMigrations())->toContain('2026_08_06_000002_create_activity_buckets_table')
        ->and(CapellCore::getProtectedTables())->toContain('activity_buckets');
});
