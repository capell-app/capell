<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Dashboard\MyWorkQueueDataProvider;
use Capell\Admin\Data\Dashboard\MyWorkItemData;
use Capell\Admin\Data\Dashboard\MyWorkQueueData;
use Capell\Admin\Filament\Widgets\Dashboard\MyWorkQueueFilamentWidget;
use Capell\Admin\Settings\AdminSettings;
use Capell\Tests\Fixtures\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
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
    expect(MyWorkQueueFilamentWidget::canView())->toBeFalse();
});

it('is hidden for users without editor, admin or developer role', function (): void {
    $user = User::factory()->createOne();
    $this->actingAs($user);

    expect(MyWorkQueueFilamentWidget::canView())->toBeFalse();
});

it('is visible for editor role', function (): void {
    app()->instance(MyWorkQueueDataProvider::class, myWorkQueueProviderWithItems());

    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.editor', 'editor'));
    $this->actingAs($user);

    expect(MyWorkQueueFilamentWidget::canView())->toBeTrue();
});

it('is visible for admin role', function (): void {
    app()->instance(MyWorkQueueDataProvider::class, myWorkQueueProviderWithItems());

    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.admin', 'admin'));
    $this->actingAs($user);

    expect(MyWorkQueueFilamentWidget::canView())->toBeTrue();
});

it('is hidden when the queue is empty', function (): void {
    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.editor', 'editor'));
    $this->actingAs($user);

    expect(MyWorkQueueFilamentWidget::canView())->toBeFalse();
});

it('is hidden when settings key is disabled', function (): void {
    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.editor', 'editor'));
    $this->actingAs($user);

    $settings = resolve(AdminSettings::class);
    $settings->enabled_widgets = ['my_work_queue' => false];
    $settings->save();

    expect(MyWorkQueueFilamentWidget::canView())->toBeFalse();
});

it('renders without errors for editor role', function (): void {
    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.editor', 'editor'));
    $this->actingAs($user);

    Livewire::test(MyWorkQueueFilamentWidget::class)->assertOk();
});

it('filters queue items by the selected dashboard date range', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-12 10:00:00'));
    app()->instance(MyWorkQueueDataProvider::class, new class implements MyWorkQueueDataProvider
    {
        public function build(Authenticatable $user, int $limit): MyWorkQueueData
        {
            return new MyWorkQueueData(
                items: MyWorkItemData::collect([
                    new MyWorkItemData(
                        pageId: 1,
                        title: 'Today draft',
                        kind: 'draft',
                        editUrl: null,
                        scheduledAt: null,
                        updatedAt: '2026-05-12T09:00:00+00:00',
                    ),
                    new MyWorkItemData(
                        pageId: 2,
                        title: 'Older draft',
                        kind: 'draft',
                        editUrl: null,
                        scheduledAt: null,
                        updatedAt: '2026-04-12T09:00:00+00:00',
                    ),
                ], DataCollection::class),
            );
        }
    });

    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.editor', 'editor'));
    $this->actingAs($user);

    $widget = new MyWorkQueueFilamentWidget;
    $widget->pageFilters = ['date_range' => 'today'];

    expect($widget->data()->items->toCollection()->pluck('title')->all())->toBe(['Today draft']);
});

it('reuses built queue data between visibility and widget hydration in one request', function (): void {
    $provider = new class implements MyWorkQueueDataProvider
    {
        public int $builds = 0;

        public function build(Authenticatable $user, int $limit): MyWorkQueueData
        {
            $this->builds++;

            return new MyWorkQueueData(
                items: MyWorkItemData::collect([
                    new MyWorkItemData(
                        pageId: 1,
                        title: 'Cached draft',
                        kind: 'draft',
                        editUrl: null,
                        scheduledAt: null,
                        updatedAt: null,
                    ),
                ], DataCollection::class),
            );
        }
    };

    app()->instance(MyWorkQueueDataProvider::class, $provider);

    $user = User::factory()->createOne();
    $user->assignRole(config('capell.roles.editor', 'editor'));
    $this->actingAs($user);

    expect(MyWorkQueueFilamentWidget::canView())->toBeTrue()
        ->and((new MyWorkQueueFilamentWidget)->data()->items->count())->toBe(1)
        ->and($provider->builds)->toBe(1);
});

function myWorkQueueProviderWithItems(): MyWorkQueueDataProvider
{
    return new class implements MyWorkQueueDataProvider
    {
        public function build(Authenticatable $user, int $limit): MyWorkQueueData
        {
            return new MyWorkQueueData(
                items: MyWorkItemData::collect([
                    new MyWorkItemData(
                        pageId: 1,
                        title: 'Draft page',
                        kind: 'draft',
                        editUrl: null,
                        scheduledAt: null,
                        updatedAt: null,
                    ),
                ], DataCollection::class),
            );
        }
    };
}
