<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildPageRankingsAction;
use Capell\Admin\Contracts\Dashboard\DashboardPageRankingProvider;
use Capell\Admin\Data\Dashboard\DashboardPageRankingItemData;
use Capell\Admin\Data\Dashboard\DashboardPageRankingSourceData;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\RisingPagesWidget;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-28 12:00:00 UTC'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('ranks scoped pages by meaningful growth and distinguishes genuinely new pages', function (): void {
    test()->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.analytics.rankings', ['enabled' => true, 'measure' => 'Page views', 'source' => 'Example source']);
    $site = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $rising = Page::factory()->createOne(['site_id' => $site->id, 'name' => 'Rising']);
    $small = Page::factory()->createOne(['site_id' => $site->id, 'name' => 'Small change']);
    $new = Page::factory()->createOne(['site_id' => $site->id, 'name' => 'New page']);
    $foreign = Page::factory()->createOne(['site_id' => $otherSite->id, 'name' => 'Other site']);
    $items = [
        new DashboardPageRankingItemData($rising->id, $site->id, $site->name, 'Rising', 15, 10, false),
        new DashboardPageRankingItemData($small->id, $site->id, $site->name, 'Small change', 11, 10, false),
        new DashboardPageRankingItemData($new->id, $site->id, $site->name, 'New page', 12, 0, true),
        new DashboardPageRankingItemData($foreign->id, $otherSite->id, $otherSite->name, 'Other site', 100, 1, false),
    ];
    app()->instance(DashboardPageRankingProvider::class, new readonly class($items) implements DashboardPageRankingProvider
    {
        /** @param list<DashboardPageRankingItemData> $items */
        public function __construct(private array $items) {}

        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language, int $days): DashboardPageRankingSourceData
        {
            return new DashboardPageRankingSourceData(true, true, true, 'Page views', 'Example source', 'UTC', $this->items, sitesRequested: 1, sitesCovered: 1, lastUpdated: '2026-10-27T00:00:00+00:00', periodStart: '2026-09-28', periodEnd: '2026-10-28');
        }
    });
    $action = resolve(BuildPageRankingsAction::class);
    $results = $action->handle(test()->authenticatedUser(), $site->id, null, 30, true);
    expect(array_map(static fn (DashboardPageRankingItemData $item): string => $item->title, $results->items))->toBe(['New page', 'Rising']);
    $popular = $action->handle(test()->authenticatedUser(), $site->id, null, 30, false);
    expect(array_map(static fn (DashboardPageRankingItemData $item): string => $item->title, $popular->items))->toBe(['Rising', 'New page', 'Small change']);
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    Livewire::test(RisingPagesWidget::class, ['pageFilters' => ['site_id' => $site->id, 'date_range' => 'last_30_days']])
        ->assertSee('Rising')->assertSee('10 previously')->assertSee('+5 (50.0%)')->assertSee('New page')
        ->assertDontSee('Other site');
});

it('does not claim a ranking when the provider has insufficient history', function (): void {
    test()->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.analytics.rankings', ['enabled' => true, 'measure' => 'Page views', 'source' => 'Source']);
    Site::factory()->createOne();
    app()->instance(DashboardPageRankingProvider::class, new class implements DashboardPageRankingProvider
    {
        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language, int $days): DashboardPageRankingSourceData
        {
            return new DashboardPageRankingSourceData(true, true, false, 'Page views', 'Source', 'UTC', [], sitesRequested: 1, sitesCovered: 1, lastUpdated: '2026-10-27T00:00:00+00:00', periodStart: '2026-09-28', periodEnd: '2026-10-28');
        }
    });
    expect(resolve(BuildPageRankingsAction::class)->handle(test()->authenticatedUser(), null, null, 30, true)->status)->toBe('insufficient');
});

it('requires deliberate matching source and measure mapping', function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();
    $provider = new class implements DashboardPageRankingProvider
    {
        public int $calls = 0;

        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language, int $days): DashboardPageRankingSourceData
        {
            $this->calls++;

            return new DashboardPageRankingSourceData(true, true, true, 'Page views', 'Actual source', 'UTC', [], sitesRequested: 1, sitesCovered: 1, lastUpdated: '2026-10-27T00:00:00+00:00', periodStart: '2026-09-28', periodEnd: '2026-10-28');
        }
    };
    app()->instance(DashboardPageRankingProvider::class, $provider);
    $action = resolve(BuildPageRankingsAction::class);
    config()->set('capell-admin.dashboard.work_first.analytics.rankings', ['enabled' => false]);
    expect($action->handle(test()->authenticatedUser(), null, null, 30, false)->status)->toBe('unavailable')->and($provider->calls)->toBe(0);
    config()->set('capell-admin.dashboard.work_first.analytics.rankings', ['enabled' => true, 'measure' => 'Page views', 'source' => 'Different source']);
    expect($action->handle(test()->authenticatedUser(), null, null, 30, false)->status)->toBe('insufficient');
});

it('does not present partial site coverage as an all-sites ranking', function (): void {
    test()->actingAsAdmin();
    $coveredSite = Site::factory()->createOne();
    Site::factory()->createOne();
    config()->set('capell-admin.dashboard.work_first.analytics.rankings', ['enabled' => true, 'measure' => 'Page views', 'source' => 'Source']);
    app()->instance(DashboardPageRankingProvider::class, new readonly class($coveredSite->id) implements DashboardPageRankingProvider
    {
        public function __construct(private int $coveredSite) {}

        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language, int $days): DashboardPageRankingSourceData
        {
            return new DashboardPageRankingSourceData(true, true, true, 'Page views', 'Source', 'UTC', [], sitesRequested: 1, sitesCovered: $siteId === $this->coveredSite ? 1 : 0, lastUpdated: '2026-10-27T00:00:00+00:00', periodStart: '2026-09-28', periodEnd: '2026-10-28');
        }
    });
    $data = resolve(BuildPageRankingsAction::class)->handle(test()->authenticatedUser(), null, null, 30, false);
    expect($data->status)->toBe('insufficient')->and($data->sitesRequested)->toBe(2)->and($data->sitesCovered)->toBe(1);
});

it('marks retained rankings stale and reports failed fetches without fabricating zero', function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();
    config()->set('capell-admin.dashboard.work_first.analytics.rankings', ['enabled' => true, 'measure' => 'Page views', 'source' => 'Source']);
    $provider = new class implements DashboardPageRankingProvider
    {
        public bool $fail = false;

        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language, int $days): DashboardPageRankingSourceData
        {
            throw_if($this->fail, RuntimeException::class, 'source unavailable');

            return new DashboardPageRankingSourceData(true, true, true, 'Page views', 'Source', 'UTC', [], sitesRequested: 1, sitesCovered: 1, status: 'stale', lastUpdated: '2026-10-27T00:00:00+00:00', periodStart: '2026-09-28', periodEnd: '2026-10-28');
        }
    };
    app()->instance(DashboardPageRankingProvider::class, $provider);
    $action = resolve(BuildPageRankingsAction::class);
    $stale = $action->handle(test()->authenticatedUser(), null, null, 30, false);
    expect($stale->status)->toBe('stale')->and($stale->lastUpdated)->toBe('2026-10-27T00:00:00+00:00');
    $provider->fail = true;
    $failed = $action->handle(test()->authenticatedUser(), null, null, 30, false);
    expect($failed->status)->toBe('failed')->and($failed->items)->toBe([]);
});

it('retains only the scoped last-successful ranking when a fetch fails', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $page = Page::factory()->createOne(['site_id' => $site->id, 'name' => 'Cached page']);
    config()->set('capell-admin.dashboard.work_first.analytics.rankings', ['enabled' => true, 'measure' => 'Page views', 'source' => 'Cache source']);
    $item = new DashboardPageRankingItemData($page->id, $site->id, $site->name, 'Cached page', 15, 10, false, '/public', '/edit', '/report');
    $provider = new class($item) implements DashboardPageRankingProvider
    {
        public bool $fail = false;

        public function __construct(private readonly DashboardPageRankingItemData $item) {}

        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language, int $days): DashboardPageRankingSourceData
        {
            throw_if($this->fail, RuntimeException::class, 'source offline');

            return new DashboardPageRankingSourceData(true, true, true, 'Page views', 'Cache source', 'UTC', [$this->item], sitesRequested: 1, sitesCovered: 1, lastUpdated: '2026-10-27T00:00:00+00:00', periodStart: '2026-09-28', periodEnd: '2026-10-28');
        }
    };
    app()->instance(DashboardPageRankingProvider::class, $provider);
    $action = resolve(BuildPageRankingsAction::class);
    expect($action->handle(test()->authenticatedUser(), $site->id, null, 30, true)->status)->toBe('available');
    $provider->fail = true;
    CarbonImmutable::setTestNow('2026-11-12 12:00:00 UTC');
    $stale = $action->handle(test()->authenticatedUser(), $site->id, null, 30, true);
    expect($stale->status)->toBe('stale')->and($stale->items[0]->title)->toBe('Cached page')
        ->and($stale->items[0]->current)->toBe(15)->and($stale->items[0]->editUrl)->toBeNull()
        ->and($stale->periodStart)->toBe('2026-09-28')->and($stale->periodEnd)->toBe('2026-10-28');
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    Livewire::test(RisingPagesWidget::class, ['pageFilters' => ['site_id' => $site->id, 'date_range' => 'last_30_days']])
        ->assertSee('2026-09-28')->assertSee('2026-10-27')->assertSee('UTC');
    expect($action->handle(test()->authenticatedUser(), $otherSite->id, null, 30, true)->status)->toBe('failed');
    config()->set('capell-admin.dashboard.work_first.analytics.rankings.source', 'Different source');
    expect($action->handle(test()->authenticatedUser(), $site->id, null, 30, true)->status)->toBe('failed');
    config()->set('capell-admin.dashboard.work_first.analytics.rankings.source', 'Cache source');
    $page->delete();
    $afterRemoval = $action->handle(test()->authenticatedUser(), $site->id, null, 30, true);
    expect($afterRemoval->status)->toBe('stale')->and($afterRemoval->items)->toBe([])->and($afterRemoval->total)->toBe(0);
});

it('does not present page rankings without reporting dates', function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();
    config()->set('capell-admin.dashboard.work_first.analytics.rankings', ['enabled' => true, 'measure' => 'Page views', 'source' => 'Source']);
    app()->instance(DashboardPageRankingProvider::class, new class implements DashboardPageRankingProvider
    {
        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language, int $days): DashboardPageRankingSourceData
        {
            return new DashboardPageRankingSourceData(true, true, true, 'Page views', 'Source', 'UTC', [], sitesRequested: 1, sitesCovered: 1, lastUpdated: '2026-10-27T00:00:00+00:00');
        }
    });
    expect(resolve(BuildPageRankingsAction::class)->handle(test()->authenticatedUser(), null, null, 30, false)->status)->toBe('insufficient');
});
