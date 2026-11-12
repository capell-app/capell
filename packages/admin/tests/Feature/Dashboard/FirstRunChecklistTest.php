<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\ResolveFirstRunStateAction;
use Capell\Admin\Contracts\Dashboard\DashboardAnalyticsDataProvider;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\DashboardRegionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsTrendFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\FirstRunChecklistFilamentWidget;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\CompositionDashboard;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\RecordedVisitsAnalyticsDataProvider;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('widget');

/**
 * @param  array<string, mixed>  $homeAttributes
 * @return array{0: Site, 1: Page}
 */
function firstRunSite(array $homeAttributes = []): array
{
    $site = Site::factory()->createOne();
    SiteDomain::query()->where('site_id', $site->id)->forceDelete();
    SiteDomain::factory()->site($site)->default()->createOne();
    $home = Page::factory()->home()->site($site)->createOne([
        'visible_from' => PublishSentinel::draftValue(),
        ...$homeAttributes,
    ]);

    return [$site, $home];
}

/** @return array<string, bool> */
function firstRunTaskStates(): array
{
    $state = ResolveFirstRunStateAction::run(auth()->user());

    return collect($state->tasks)->mapWithKeys(fn ($task): array => [$task->key => $task->complete])->all();
}

it('shows the five first-run tasks with what to do and why on a fresh install', function (): void {
    $this->actingAsAdmin();
    firstRunSite();

    expect(FirstRunChecklistFilamentWidget::canView())->toBeTrue();

    Livewire::test(FirstRunChecklistFilamentWidget::class)
        ->assertOk()
        ->assertSee(__('capell-admin::dashboard.first_run_heading'))
        ->assertSeeInOrder([
            __('capell-admin::dashboard.first_run_task_site_title'),
            __('capell-admin::dashboard.first_run_task_homepage_title'),
            __('capell-admin::dashboard.first_run_task_logo_title'),
            __('capell-admin::dashboard.first_run_task_address_title'),
            __('capell-admin::dashboard.first_run_task_publish_title'),
        ])
        ->assertSee(__('capell-admin::dashboard.first_run_task_logo_consequence'))
        ->assertSee(__('capell-admin::dashboard.first_run_description', ['done' => 0, 'total' => 5]))
        ->assertSee(__('capell-admin::dashboard.first_run_next', ['task' => __('capell-admin::dashboard.first_run_task_site_title')]));

    expect(firstRunTaskStates())->toBe([
        'site' => false,
        'homepage' => false,
        'logo' => false,
        'address' => false,
        'publish' => false,
    ]);
});

it('ticks tasks itself as they are done', function (): void {
    $this->actingAsAdmin();
    [$site, $home] = firstRunSite();
    $site->forceFill(['meta' => ['guided_setup' => ['completed_at' => now()->toIso8601String()]]])->save();

    Media::factory()->model($site)->createOne(['collection_name' => MediaCollectionEnum::Logo->value]);
    SiteDomain::query()->where('site_id', $site->id)->update(['domain' => 'example.org']);
    PageRevision::query()->create([
        'page_uuid' => $home->uuid,
        'version' => 1,
        'actor_id' => auth()->id(),
        'summary' => 'Edited',
        'is_rollback' => false,
        'source' => 'edit',
        'occurred_at' => now(),
    ]);

    expect(firstRunTaskStates())->toBe([
        'site' => true,
        'homepage' => true,
        'logo' => true,
        'address' => true,
        'publish' => false,
    ]);
});

it('does not count an import or console save of the homepage as done', function (): void {
    $this->actingAsAdmin();
    [, $home] = firstRunSite();
    PageRevision::query()->create(['page_uuid' => $home->uuid, 'version' => 1, 'actor_id' => auth()->id(), 'summary' => 'Imported', 'is_rollback' => false, 'source' => 'import', 'occurred_at' => now()]);
    PageRevision::query()->create(['page_uuid' => $home->uuid, 'version' => 2, 'actor_id' => null, 'summary' => 'Seeded', 'is_rollback' => false, 'source' => 'edit', 'occurred_at' => now()]);

    expect(firstRunTaskStates()['homepage'])->toBeFalse();
});

it('does not count the starter homepage or a local address as done', function (): void {
    $this->actingAsAdmin();
    [$site] = firstRunSite();
    SiteDomain::query()->where('site_id', $site->id)->update(['domain' => 'shop.test']);

    expect(firstRunTaskStates())->toMatchArray(['homepage' => false, 'address' => false]);
});

it('leaves first-run state once the homepage is public', function (): void {
    $this->actingAsAdmin();
    [, $home] = firstRunSite();
    $home->forceFill(['visible_from' => now()->subDay()])->saveQuietly();

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeFalse()
        ->and(FirstRunChecklistFilamentWidget::canView())->toBeFalse();
});

it('keeps a homepage published only as hidden from search engines in first-run state', function (): void {
    $this->actingAsAdmin();
    [, $home] = firstRunSite();
    $home->forceFill(['visible_from' => now()->subDay(), 'meta' => ['noindex' => true]])->saveQuietly();

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeTrue();
});

it('is not first-run when there is no site or homepage yet', function (): void {
    $this->actingAsAdmin();

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeFalse();

    Site::factory()->createOne();

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeFalse()
        ->and(FirstRunChecklistFilamentWidget::canView())->toBeFalse();
});

it('hides analytics panels on the dashboard during first run and restores them once public', function (): void {
    $this->actingAsAdmin();
    [, $home] = firstRunSite();
    app()->singleton(DashboardAnalyticsDataProvider::class, RecordedVisitsAnalyticsDataProvider::class);
    CapellAdmin::registerDashboardPanel(DashboardRegionEnum::Trends, AnalyticsTrendFilamentWidget::class, DashboardEnum::NotInstalled);
    $dashboard = new CompositionDashboard;
    $dashboard->widgets = [AnalyticsTrendFilamentWidget::class];

    $widgetClasses = fn (): array => collect(compositionRegionWidgetClasses($dashboard->getWidgetsContentComponent()))->all();

    expect($widgetClasses())->not->toContain(AnalyticsTrendFilamentWidget::class);

    $home->forceFill(['visible_from' => now()->subDay()])->saveQuietly();
    app()->forgetScopedInstances();

    expect($widgetClasses())->toContain(AnalyticsTrendFilamentWidget::class);
});

/** @return list<string> */
function compositionRegionWidgetClasses(mixed $component): array
{
    $classes = [];
    $walk = function (mixed $node) use (&$walk, &$classes): void {
        if ($node instanceof Filament\Schemas\Components\Livewire) {
            $classes[] = $node->getComponent();

            return;
        }

        if ($node instanceof Component) {
            $children = $node->getDefaultChildComponents();
            if ($children instanceof Schema) {
                $children = $children->getComponents();
            }

            foreach ($children as $child) {
                $walk($child);
            }
        }
    };
    $walk($component);

    return $classes;
}

it('is not first-run once every accessible site with a homepage is live', function (): void {
    $this->actingAsAdmin();
    $live = Site::factory()->createOne();
    Page::factory()->home()->site($live)->createOne(['visible_from' => now()->subDay()]);

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeFalse();

    firstRunSite();

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeTrue();
});

it('treats a robots noindex homepage as not yet public', function (): void {
    $this->actingAsAdmin();
    [, $home] = firstRunSite();
    $home->forceFill(['visible_from' => now()->subDay(), 'meta' => ['robots' => 'noindex,nofollow']])->saveQuietly();

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeTrue();
});

it('ticks the homepage task after the homepage is saved as a draft through the admin editor', function (): void {
    $this->actingAsAdmin();
    [$site] = firstRunSite();
    Page::query()->where('site_id', $site->id)->forceDelete();
    $home = Page::factory()->home()->site($site)->withTranslations()->createOne(['visible_from' => PublishSentinel::draftValue()]);

    expect(firstRunTaskStates()['homepage'])->toBeFalse();

    Livewire::test(EditPage::class, ['record' => $home->getRouteKey()])
        ->fillForm(['name' => 'Our new homepage'])
        ->call('saveAsDraft')
        ->assertHasNoFormErrors();

    expect($home->refresh()->name)->toBe('Our new homepage');

    app()->forgetScopedInstances();

    expect(firstRunTaskStates()['homepage'])->toBeTrue();
});

it('ticks Set up your site from guided setup or an imported site spec, and not from skipping it', function (array $meta, bool $expected): void {
    $this->actingAsAdmin();
    [$site] = firstRunSite();
    $site->forceFill(['meta' => $meta])->save();

    expect(firstRunTaskStates()['site'])->toBe($expected);
})->with([
    'nothing recorded' => [[], false],
    'skipped' => [['guided_setup' => ['skipped_at' => '2026-11-12T10:00:00+00:00']], false],
    'guided setup finished' => [['guided_setup' => ['completed_at' => '2026-11-12T10:00:00+00:00']], true],
    'site spec imported' => [['site_spec_applied_hash' => 'abc123'], true],
]);
