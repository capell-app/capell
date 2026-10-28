<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildPageViewsAction;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Models\Site;
use Capell\Core\Support\Metrics\ActivityBucketsDailyMetricsCollector;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

uses(CreatesAdminUser::class);

it('requires complete scope coverage and preserves valid zero page views', function (): void {
    test()->actingAsAdmin();
    CarbonImmutable::setTestNow('2026-10-28 12:00:00 UTC');
    $site = Site::factory()->createOne();
    $language = $site->language->code;
    $scope = MetricScopeData::siteLanguage($site->uuid, $language, 'UTC')->key();
    $hash = (new ActivityBucketsDailyMetricsCollector)->definitions()[0]->semanticHash();
    foreach (['2026-10-26' => 4, '2026-10-27' => 0] as $day => $value) {
        $runId = DB::table('metric_collection_runs')->insertGetId([
            'day' => $day, 'owner_package' => 'capell-app/core', 'collector_key' => 'activity',
            'definition_hash' => $hash, 'status' => 'completed', 'started_at' => now(), 'completed_at' => now(),
        ]);
        DB::table('metric_daily_rollups')->insert([
            'metric_collection_run_id' => $runId, 'day' => $day, 'owner_package' => 'capell-app/core',
            'collector_key' => 'activity', 'metric_key' => 'page_views', 'definition_hash' => $hash,
            'scope_key' => $scope, 'scope_type' => 'site_language', 'site_id' => $site->id, 'site_uuid' => $site->uuid,
            'language' => $language, 'timezone' => 'UTC', 'day_starts_at' => '00:00:00', 'unit' => 'count',
            'value_type' => 'integer', 'value' => (string) $value, 'point_state' => $value === 0 ? 'zero' : 'present',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $connection = DB::connection();
    $originalEvents = $connection->getEventDispatcher();
    throw_if($originalEvents === null, LogicException::class, 'The database event dispatcher is required for this test.');

    $connection->setEventDispatcher(clone $originalEvents);
    $connection->listen(static function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'metric_daily_rollups')) {
            throw new QueryException('sqlite', $query->sql, [], new RuntimeException('simulated first read failure'));
        }
    });
    try {
        $failed = resolve(BuildPageViewsAction::class)->handle(test()->authenticatedUser(), $site->id, null, 2);
        expect($failed->status)->toBe('failed')->and($failed->current)->toBeNull();
    } finally {
        $connection->setEventDispatcher($originalEvents);
    }

    $data = resolve(BuildPageViewsAction::class)->handle(test()->authenticatedUser(), $site->id, null, 2);
    expect($data->status)->toBe('available')->and($data->current)->toBe(4)->and($data->previous)->toBeNull()
        ->and($data->trend)->toBe([['day' => '2026-10-26', 'count' => 4], ['day' => '2026-10-27', 'count' => 0]]);

    $connection = DB::connection();
    $originalEvents = $connection->getEventDispatcher();
    throw_if($originalEvents === null, LogicException::class, 'The database event dispatcher is required for this test.');

    $connection->setEventDispatcher(clone $originalEvents);
    $connection->listen(static function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'metric_daily_rollups')) {
            throw new QueryException('sqlite', $query->sql, [], new RuntimeException('simulated read failure'));
        }
    });
    try {
        $stale = resolve(BuildPageViewsAction::class)->handle(test()->authenticatedUser(), $site->id, null, 2);
        expect($stale->status)->toBe('stale')->and($stale->current)->toBe(4);
    } finally {
        $connection->setEventDispatcher($originalEvents);
    }

    DB::table('metric_daily_rollups')->where('day', '2026-10-26')->delete();
    $incomplete = resolve(BuildPageViewsAction::class)->handle(test()->authenticatedUser(), $site->id, null, 2);
    expect($incomplete->status)->toBe('insufficient')->and($incomplete->current)->toBeNull();
    CarbonImmutable::setTestNow();
});

it('reports a disconnected collector rather than zero traffic', function (): void {
    test()->actingAsAdmin();
    $settings = AdminSettings::instance();
    $settings->analytics_collection_enabled = false;
    $settings->save();

    $data = resolve(BuildPageViewsAction::class)->handle(test()->authenticatedUser(), null, null);
    expect($data->status)->toBe('disconnected')->and($data->current)->toBeNull();
});

it('does not expose analytics to users without an analytics role', function (): void {
    $user = test()->createUser();
    $data = resolve(BuildPageViewsAction::class)->handle($user, null, null);

    expect($data->status)->toBe('unauthorised')->and($data->current)->toBeNull();
});
