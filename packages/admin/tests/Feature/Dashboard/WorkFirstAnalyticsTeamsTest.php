<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildPageRankingsAction;
use Capell\Admin\Actions\Dashboard\BuildPageViewsAction;
use Capell\Admin\Actions\Dashboard\BuildVisitorsSnapshotAction;
use Capell\Admin\Contracts\Dashboard\DashboardPageRankingProvider;
use Capell\Admin\Contracts\Dashboard\DashboardVisitorsProvider;
use Capell\Admin\Data\Dashboard\DashboardPageRankingSourceData;
use Capell\Admin\Data\Dashboard\DashboardVisitorsData;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Models\Site;
use Capell\Core\Support\Metrics\ActivityBucketsDailyMetricsCollector;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => true, 'capell.roles.admin' => 'work-first-analytics-admin']);
    resolve(PermissionRegistrar::class)->teams = true;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    CarbonImmutable::setTestNow('2026-10-28 12:00:00 UTC');
});

afterEach(function (): void {
    auth()->logout();
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['permission.teams' => false]);
    CarbonImmutable::setTestNow();
});

it('checks analytics permission independently for each assigned site and restores the active team', function (): void {
    $siteA = Site::factory()->createOne();
    $siteB = Site::factory()->createOne();
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(null);

    $admin = Role::query()->firstOrCreate(['name' => 'work-first-analytics-admin', 'guard_name' => 'web']);
    $editor = Role::query()->firstOrCreate(['name' => 'work-first-analytics-editor', 'guard_name' => 'web']);
    $permission = Permission::findOrCreate('ViewAny:Page', 'web');
    $admin->givePermissionTo($permission);
    $user = new RealSiteScopedAdminResourceUser;
    $user->forceFill(['name' => 'Site editor', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $user->save();
    $user->refresh();
    $user->assignRoleForSite($siteA, $admin);
    $user->assignRoleForSite($siteB, $editor);

    $registrar->setPermissionsTeamId($siteA->id);
    test()->actingAs($user);

    $hash = (new ActivityBucketsDailyMetricsCollector)->definitions()[0]->semanticHash();
    $runId = DB::table('metric_collection_runs')->insertGetId([
        'day' => '2026-10-27', 'owner_package' => 'capell-app/core', 'collector_key' => 'activity',
        'definition_hash' => $hash, 'status' => 'completed', 'started_at' => now(), 'completed_at' => now(),
    ]);
    foreach ([[$siteA, 3], [$siteB, 9]] as [$site, $count]) {
        $language = $site->language->code;
        DB::table('metric_daily_rollups')->insert([
            'metric_collection_run_id' => $runId, 'day' => '2026-10-27', 'owner_package' => 'capell-app/core',
            'collector_key' => 'activity', 'metric_key' => 'page_views', 'definition_hash' => $hash,
            'scope_key' => MetricScopeData::siteLanguage($site->uuid, $language, 'UTC')->key(), 'scope_type' => 'site_language',
            'site_id' => $site->id, 'site_uuid' => $site->uuid, 'language' => $language,
            'timezone' => 'UTC', 'day_starts_at' => '00:00:00', 'unit' => 'count', 'value_type' => 'integer',
            'value' => (string) $count, 'point_state' => 'present', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $views = resolve(BuildPageViewsAction::class)->handle($user, null, null, 1);
    expect($views->current)->toBe(3)->and($views->sitesRequested)->toBe(1)
        ->and(resolve(BuildPageViewsAction::class)->handle($user, $siteB->id, null, 1)->status)->toBe('unauthorised');

    config()->set('capell-admin.dashboard.work_first.analytics.visitors', ['enabled' => true, 'measure' => 'Visitors', 'source' => 'Source']);
    $visitorProvider = new class implements DashboardVisitorsProvider
    {
        /** @var list<int> */
        public array $sites = [];

        #[Override]
        public function build(Authenticatable $actor, int $siteId, ?string $language, int $days): DashboardVisitorsData
        {
            $this->sites[] = $siteId;

            return new DashboardVisitorsData('available', 'Visitors', 'Source', 'UTC', 7, 6, '2026-10-27T10:00:00+00:00', '2026-10-27', '2026-10-28');
        }
    };
    app()->instance(DashboardVisitorsProvider::class, $visitorProvider);
    $visitors = resolve(BuildVisitorsSnapshotAction::class);
    expect($visitors->handle($user, $siteA->id, null, 1)?->current)->toBe(7)
        ->and($visitors->handle($user, $siteB->id, null, 1))->toBeNull()
        ->and($visitorProvider->sites)->toBe([$siteA->id]);

    config()->set('capell-admin.dashboard.work_first.analytics.rankings', ['enabled' => true, 'measure' => 'Page views', 'source' => 'Source']);
    $rankingProvider = new class implements DashboardPageRankingProvider
    {
        /** @var list<array{site: int|null, team: int|string|null}> */
        public array $calls = [];

        public bool $fail = false;

        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language, int $days): DashboardPageRankingSourceData
        {
            $this->calls[] = ['site' => $siteId, 'team' => resolve(PermissionRegistrar::class)->getPermissionsTeamId()];
            throw_if($this->fail, RuntimeException::class, 'source offline');

            return new DashboardPageRankingSourceData(true, true, true, 'Page views', 'Source', 'UTC', [], sitesRequested: 1, sitesCovered: 1, lastUpdated: '2026-10-27T10:00:00+00:00', periodStart: '2026-10-27', periodEnd: '2026-10-28');
        }
    };
    app()->instance(DashboardPageRankingProvider::class, $rankingProvider);
    $rankings = resolve(BuildPageRankingsAction::class);
    expect($rankings->handle($user, null, null, 1, false)->status)->toBe('available')
        ->and($rankingProvider->calls)->toBe([['site' => $siteA->id, 'team' => $siteA->id]])
        ->and($registrar->getPermissionsTeamId())->toBe($siteA->id);

    $user->assignRoleForSite($siteB, $admin);
    $registrar->setPermissionsTeamId($siteA->id);
    $rankingProvider->calls = [];
    $rankingProvider->fail = true;
    expect($rankings->handle($user, null, null, 1, false)->status)->toBe('failed')
        ->and($registrar->getPermissionsTeamId())->toBe($siteA->id);
    $rankingProvider->calls = [];
    $rankingProvider->fail = false;

    $views = resolve(BuildPageViewsAction::class)->handle($user, null, null, 1);
    expect($views->current)->toBe(12)->and($views->sitesRequested)->toBe(2);
    expect($rankings->handle($user, null, null, 1, false)->status)->toBe('available')
        ->and(array_column($rankingProvider->calls, 'site'))->toEqualCanonicalizing([$siteA->id, $siteB->id])
        ->and($registrar->getPermissionsTeamId())->toBe($siteA->id);

    $user->removeRoleForSite($siteB, $admin);
    $registrar->setPermissionsTeamId($siteA->id);
    $rankingProvider->fail = true;
    $afterRevocation = $rankings->handle($user, null, null, 1, false);
    expect($afterRevocation->status)->toBe('stale')->and($afterRevocation->sitesRequested)->toBe(1)
        ->and($registrar->getPermissionsTeamId())->toBe($siteA->id);
});
