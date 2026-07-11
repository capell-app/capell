<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Dashboard\RecentlyPublishedDataProvider;
use Capell\Admin\Data\Dashboard\RecentlyPublishedData;
use Capell\Admin\Data\Dashboard\RecentlyPublishedItemData;
use Capell\Admin\Filament\Widgets\Dashboard\RecentlyPublishedFilamentWidget;
use Capell\Admin\Settings\AdminSettings;
use Capell\Tests\Fixtures\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\LaravelData\DataCollection;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::findOrCreate(config('capell.roles.editor', 'editor'));
    Role::findOrCreate(config('capell.roles.admin', 'admin'));
    Role::findOrCreate(config('capell.roles.developer', 'developer'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('is hidden when unauthenticated', function (): void {
    expect(RecentlyPublishedFilamentWidget::canView())->toBeFalse();
});

it('is hidden for users without editor, admin or developer role', function (): void {
    $user = User::factory()->createOne();
    $this->actingAs($user);

    expect(RecentlyPublishedFilamentWidget::canView())->toBeFalse();
});

it('is visible for editor role', function (): void {
    app()->instance(RecentlyPublishedDataProvider::class, recentlyPublishedProviderWithItems());

    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.editor', 'editor'));
    $this->actingAs($user);

    expect(RecentlyPublishedFilamentWidget::canView())->toBeTrue();
});

it('is visible for admin role', function (): void {
    app()->instance(RecentlyPublishedDataProvider::class, recentlyPublishedProviderWithItems());

    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.admin', 'admin'));
    $this->actingAs($user);

    expect(RecentlyPublishedFilamentWidget::canView())->toBeTrue();
});

it('is hidden when there are no recently published items', function (): void {
    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.editor', 'editor'));
    $this->actingAs($user);

    expect(RecentlyPublishedFilamentWidget::canView())->toBeFalse();
});

it('is hidden when settings key is disabled', function (): void {
    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.editor', 'editor'));
    $this->actingAs($user);

    $settings = resolve(AdminSettings::class);
    $settings->enabled_widgets = ['recently_published' => false];
    $settings->save();

    expect(RecentlyPublishedFilamentWidget::canView())->toBeFalse();
});

it('renders without errors for editor role', function (): void {
    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.editor', 'editor'));
    $this->actingAs($user);

    Livewire::test(RecentlyPublishedFilamentWidget::class)->assertOk();
});

it('filters recently published items by the selected dashboard date range', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-12 10:00:00'));
    app()->instance(RecentlyPublishedDataProvider::class, new class implements RecentlyPublishedDataProvider
    {
        public function build(int $limit): RecentlyPublishedData
        {
            return new RecentlyPublishedData(
                items: RecentlyPublishedItemData::collect([
                    new RecentlyPublishedItemData(
                        pageId: 1,
                        title: 'Published today',
                        siteName: 'Main site',
                        publishedAt: '2026-05-12T09:00:00+00:00',
                        editUrl: null,
                    ),
                    new RecentlyPublishedItemData(
                        pageId: 2,
                        title: 'Published last month',
                        siteName: 'Main site',
                        publishedAt: '2026-04-12T09:00:00+00:00',
                        editUrl: null,
                    ),
                ], DataCollection::class),
            );
        }
    });

    $widget = new RecentlyPublishedFilamentWidget;
    $widget->pageFilters = ['date_range' => 'today'];

    expect($widget->data()->items->toCollection()->pluck('title')->all())->toBe(['Published today']);
});

it('reuses recently published data between visibility and widget hydration in one request', function (): void {
    $provider = new class implements RecentlyPublishedDataProvider
    {
        public int $builds = 0;

        public function build(int $limit): RecentlyPublishedData
        {
            $this->builds++;

            return new RecentlyPublishedData(
                items: RecentlyPublishedItemData::collect([
                    new RecentlyPublishedItemData(
                        pageId: 1,
                        title: 'Cached published page',
                        siteName: 'Main site',
                        publishedAt: null,
                        editUrl: null,
                    ),
                ], DataCollection::class),
            );
        }
    };

    app()->instance(RecentlyPublishedDataProvider::class, $provider);

    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.editor', 'editor'));
    $this->actingAs($user);

    expect(RecentlyPublishedFilamentWidget::canView())->toBeTrue()
        ->and((new RecentlyPublishedFilamentWidget)->data()->items->count())->toBe(1)
        ->and($provider->builds)->toBe(1);
});

function recentlyPublishedProviderWithItems(): RecentlyPublishedDataProvider
{
    return new class implements RecentlyPublishedDataProvider
    {
        public function build(int $limit): RecentlyPublishedData
        {
            return new RecentlyPublishedData(
                items: RecentlyPublishedItemData::collect([
                    new RecentlyPublishedItemData(
                        pageId: 1,
                        title: 'Published page',
                        siteName: 'Main site',
                        publishedAt: null,
                        editUrl: null,
                    ),
                ], DataCollection::class),
            );
        }
    };
}
