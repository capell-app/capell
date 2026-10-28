<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildVisitorsSnapshotAction;
use Capell\Admin\Contracts\Dashboard\DashboardVisitorsProvider;
use Capell\Admin\Data\Dashboard\DashboardVisitorsData;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\PageViewsWidget;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-28 12:00:00 UTC'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('requires a configured matching single-site visitor measure and preserves zero', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    $provider = new class implements DashboardVisitorsProvider
    {
        public int $calls = 0;

        public bool $fail = false;

        #[Override]
        public function build(Authenticatable $actor, int $siteId, ?string $language, int $days): DashboardVisitorsData
        {
            $this->calls++;
            throw_if($this->fail, RuntimeException::class, 'source unavailable');

            return new DashboardVisitorsData('available', 'Visitors', 'Example', 'Europe/London', 0, 12, '2026-10-27T00:00:00+00:00', '2026-09-28', '2026-10-28');
        }
    };
    app()->instance(DashboardVisitorsProvider::class, $provider);
    $action = resolve(BuildVisitorsSnapshotAction::class);
    expect($action->handle(test()->authenticatedUser(), $site->id, null, 30))->toBeNull()->and($provider->calls)->toBe(0);
    config()->set('capell-admin.dashboard.work_first.analytics.visitors', ['enabled' => true, 'measure' => 'Visitors', 'source' => 'Example']);
    expect($action->handle(test()->authenticatedUser(), null, null, 30))->toBeNull()->and($provider->calls)->toBe(0);
    $data = $action->handle(test()->authenticatedUser(), $site->id, null, 30);
    expect($data?->current)->toBe(0)->and($data?->previous)->toBe(12)->and($data?->timezone)->toBe('Europe/London');
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    Livewire::test(PageViewsWidget::class, ['pageFilters' => ['site_id' => $site->id, 'date_range' => 'last_30_days']])
        ->assertSee('Visitors')->assertSee('Example')->assertSee('Europe/London');
    $calls = $provider->calls;
    Livewire::test(PageViewsWidget::class, ['pageFilters' => ['site_id' => null]])
        ->assertSee('Select a site to see its visitor figures');
    expect($provider->calls)->toBe($calls);
    $provider->fail = true;
    CarbonImmutable::setTestNow('2026-11-12 12:00:00 UTC');
    $stale = $action->handle(test()->authenticatedUser(), $site->id, null, 30);
    expect($stale?->status)->toBe('stale')->and($stale?->current)->toBe(0)->and($stale?->previous)->toBe(12)->and($stale?->periodStart)->toBe('2026-09-28')->and($stale?->periodEnd)->toBe('2026-10-28');
    Livewire::test(PageViewsWidget::class, ['pageFilters' => ['site_id' => $site->id, 'date_range' => 'last_30_days']])
        ->assertSee('2026-09-28')->assertSee('2026-10-27')->assertSee('Europe/London');
    $otherSite = Site::factory()->createOne();
    expect($action->handle(test()->authenticatedUser(), $otherSite->id, null, 30)?->status)->toBe('failed');
    config()->set('capell-admin.dashboard.work_first.analytics.visitors.source', 'Different');
    expect($action->handle(test()->authenticatedUser(), $site->id, null, 30)?->status)->toBe('failed');
    $provider->fail = false;
    CarbonImmutable::setTestNow('2026-10-28 12:00:00 UTC');

    expect($action->handle(test()->authenticatedUser(), $site->id, null, 30)?->status)->toBe('insufficient');
});

it('reports provider failure without showing zero visitors', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    config()->set('capell-admin.dashboard.work_first.analytics.visitors', ['enabled' => true, 'measure' => 'Visitors', 'source' => 'Example']);
    app()->instance(DashboardVisitorsProvider::class, new class implements DashboardVisitorsProvider
    {
        #[Override]
        public function build(Authenticatable $actor, int $siteId, ?string $language, int $days): DashboardVisitorsData
        {
            throw new RuntimeException('source unavailable');
        }
    });
    $data = resolve(BuildVisitorsSnapshotAction::class)->handle(test()->authenticatedUser(), $site->id, null, 30);
    expect($data?->status)->toBe('failed')->and($data?->current)->toBeNull();
});

it('does not present external figures without reporting dates', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    config()->set('capell-admin.dashboard.work_first.analytics.visitors', ['enabled' => true, 'measure' => 'Visitors', 'source' => 'Example']);
    app()->instance(DashboardVisitorsProvider::class, new class implements DashboardVisitorsProvider
    {
        #[Override]
        public function build(Authenticatable $actor, int $siteId, ?string $language, int $days): DashboardVisitorsData
        {
            return new DashboardVisitorsData('available', 'Visitors', 'Example', 'UTC', 5, 4, '2026-10-27T00:00:00+00:00');
        }
    });
    $data = resolve(BuildVisitorsSnapshotAction::class)->handle(test()->authenticatedUser(), $site->id, null, 30);
    expect($data?->status)->toBe('insufficient')->and($data?->current)->toBeNull();
});
