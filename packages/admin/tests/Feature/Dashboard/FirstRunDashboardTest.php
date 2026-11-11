<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\ResolveFirstRunStateAction;
use Capell\Admin\Contracts\Dashboard\FirstRunTaskContributor;
use Capell\Admin\Data\Dashboard\DashboardScopePreferenceData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsInsightsFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsTrendFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\FirstRunChecklistFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\NeedsAttentionFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\RecentlyUpdatedWidget;
use Capell\Admin\Support\UserMenu\UserMenuItemResolver;
use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\ContactFormFirstRunTaskContributor;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\FailingFirstRunTaskContributor;
use Capell\Core\Actions\FirstRun\RecordSitePublishedAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('widget');

/** @return array{0: Site, 1: Page} */
function unpublishedDashboardSite(): array
{
    $site = Site::factory()->createOne();
    SiteDomain::query()->where('site_id', $site->id)->forceDelete();
    SiteDomain::factory()->site($site)->default()->createOne();
    $home = Page::factory()->home()->site($site)->createOne(['visible_from' => PublishSentinel::draftValue()]);

    return [$site, $home];
}

/** The marker Livewire writes into the page for each rendered component. */
function firstRunWidgetMarker(string $widget): string
{
    return trim(e(json_encode(['name' => resolve('livewire.factory')->resolveComponentName($widget)], JSON_THROW_ON_ERROR)), '{}');
}

/** Simulates the next request: request-scoped caches start empty again. */
function nextDashboardRequest(): void
{
    app()->forgetScopedInstances();
    app()->forgetInstance(UserMenuItemResolver::class);
}

it('opens a new site on the checklist, recent updates and what needs attention only', function (): void {
    $this->actingAsAdmin();
    unpublishedDashboardSite();

    $html = Livewire::test(CapellDashboard::class)
        ->assertOk()
        ->assertDontSee(__('capell-admin::dashboard.performance_heading'))
        ->assertDontSee(__('capell-admin::dashboard.region_additional'))
        ->assertDontSee(__('filament::components/loading-section.label'))
        ->html();

    foreach ([FirstRunChecklistFilamentWidget::class, RecentlyUpdatedWidget::class, NeedsAttentionFilamentWidget::class] as $shown) {
        expect($html)->toContain(firstRunWidgetMarker($shown));
    }

    foreach ([AnalyticsOverviewFilamentWidget::class, AnalyticsTrendFilamentWidget::class, AnalyticsInsightsFilamentWidget::class, ListPagesFilamentWidget::class] as $held) {
        expect($html)->not->toContain(firstRunWidgetMarker($held));
    }
});

it('renders the first-run panels with the page instead of as loading placeholders', function (string $widget): void {
    expect($widget::getDefaultProperties())->not->toHaveKey('lazy');
})->with([
    'checklist' => FirstRunChecklistFilamentWidget::class,
    'recently updated' => RecentlyUpdatedWidget::class,
    'needs attention' => NeedsAttentionFilamentWidget::class,
]);

it('hides the checklist only for the person who chose Hide', function (): void {
    $this->actingAsAdmin();
    $user = $this->authenticatedUser();
    unpublishedDashboardSite();
    $colleague = $this->createUserWithRole('super_admin');

    Livewire::test(FirstRunChecklistFilamentWidget::class)
        ->assertSee(__('capell-admin::dashboard.first_run_hide'))
        ->assertSee(__('capell-admin::dashboard.first_run_hide_help'))
        ->call('hide')
        ->assertNotified(__('capell-admin::dashboard.first_run_hidden_title'))
        ->assertRedirect();

    nextDashboardRequest();

    expect(resolve(AdminWorkspacePreferenceStore::class)->firstRunChecklistHidden($user->refresh()))->toBeTrue()
        ->and(FirstRunChecklistFilamentWidget::canView())->toBeFalse()
        ->and(FirstRunChecklistFilamentWidget::isFirstRun())->toBeTrue();

    $html = Livewire::test(CapellDashboard::class)->html();

    expect($html)->toContain(firstRunWidgetMarker(RecentlyUpdatedWidget::class))
        ->and($html)->not->toContain(firstRunWidgetMarker(FirstRunChecklistFilamentWidget::class))
        ->and($html)->not->toContain(firstRunWidgetMarker(AnalyticsTrendFilamentWidget::class));

    $this->actingAs($colleague);
    nextDashboardRequest();

    expect(FirstRunChecklistFilamentWidget::canView())->toBeTrue();
});

it('offers the hidden checklist again under Help and brings it back from there', function (): void {
    $this->actingAsAdmin();
    unpublishedDashboardSite();
    $user = $this->authenticatedUser();

    expect(CapellAdmin::getUserMenuItems($user))->not->toHaveKey('first-run-checklist');

    resolve(AdminWorkspacePreferenceStore::class)->setFirstRunChecklistHidden($user, true);
    nextDashboardRequest();

    $item = CapellAdmin::getUserMenuItems($user)['first-run-checklist'] ?? null;
    throw_unless($item instanceof Action, LogicException::class, 'The Help item should be offered.');

    expect($item->getLabel())->toBe(__('capell-admin::dashboard.first_run_help_menu_item'))
        ->and($item->getUrl())->toContain(CapellDashboard::SHOW_FIRST_RUN_CHECKLIST_QUERY . '=1');

    nextDashboardRequest();

    Livewire::withQueryParams([CapellDashboard::SHOW_FIRST_RUN_CHECKLIST_QUERY => '1'])
        ->test(CapellDashboard::class)
        ->assertRedirect(CapellDashboard::getUrl());

    expect(resolve(AdminWorkspacePreferenceStore::class)->firstRunChecklistHidden($user->refresh()))->toBeFalse();

    nextDashboardRequest();

    expect(FirstRunChecklistFilamentWidget::canView())->toBeTrue()
        ->and(CapellAdmin::getUserMenuItems($user->refresh()))->not->toHaveKey('first-run-checklist');
});

it('decides the Help item from the saved choice and go-live dates without building the checklist', function (): void {
    $this->actingAsAdmin();
    unpublishedDashboardSite();
    $user = $this->authenticatedUser();
    app()->tag([ContactFormFirstRunTaskContributor::class], FirstRunTaskContributor::TAG);
    ContactFormFirstRunTaskContributor::$lastContext = null;
    resolve(AdminWorkspacePreferenceStore::class)->setFirstRunChecklistHidden($user, true);
    nextDashboardRequest();

    // Building the checklist always asks contributors for tasks, so an untouched contributor proves it was not built.
    expect(CapellAdmin::getUserMenuItems($user))->toHaveKey('first-run-checklist')
        ->and(ContactFormFirstRunTaskContributor::$lastContext)->toBeNull();

    ResolveFirstRunStateAction::run($user);

    expect(ContactFormFirstRunTaskContributor::$lastContext)->not->toBeNull();
});

it('does not offer the Help item once the site is live', function (): void {
    $this->actingAsAdmin();
    [$site] = unpublishedDashboardSite();
    resolve(AdminWorkspacePreferenceStore::class)->setFirstRunChecklistHidden($this->authenticatedUser(), true);
    RecordSitePublishedAction::run($site->id);
    nextDashboardRequest();

    expect(CapellAdmin::getUserMenuItems(auth()->user()))->not->toHaveKey('first-run-checklist');
});

it('stays out of first run once the site has been published, even if the homepage is unpublished again', function (): void {
    $this->actingAsAdmin();
    [$site] = unpublishedDashboardSite();

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeTrue();

    RecordSitePublishedAction::run($site->id);
    nextDashboardRequest();

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeFalse()
        ->and(FirstRunChecklistFilamentWidget::canView())->toBeFalse();
});

it('records a home page that went public on its scheduled time and keeps the site live afterwards', function (): void {
    $this->actingAsAdmin();
    [$site, $home] = unpublishedDashboardSite();
    $home->forceFill(['visible_from' => now()->addDay()])->saveQuietly();

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeTrue()
        ->and(resolve(SitePublicationMilestones::class)->hasPublished($site->id))->toBeFalse();

    $this->travel(2)->days();
    nextDashboardRequest();

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeFalse()
        ->and(resolve(SitePublicationMilestones::class)->hasPublished($site->id))->toBeTrue();

    $home->forceFill(['visible_from' => PublishSentinel::draftValue()])->saveQuietly();
    nextDashboardRequest();

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeFalse();
});

it('records a home page made public by a direct save such as a form or an import', function (): void {
    $this->actingAsAdmin();
    [$site, $home] = unpublishedDashboardSite();
    $home->visible_from = CarbonImmutable::now()->subMinute();
    $home->save();
    nextDashboardRequest();

    expect(ResolveFirstRunStateAction::run(auth()->user())->isFirstRun)->toBeFalse()
        ->and(resolve(SitePublicationMilestones::class)->hasPublished($site->id))->toBeTrue();
});

it('keeps showing the checklist for an unpublished site when another site is already live', function (): void {
    $this->actingAsAdmin();
    $live = Site::factory()->createOne();
    Page::factory()->home()->site($live)->createOne(['visible_from' => now()->subMonth()]);
    [$draft, $draftHome] = unpublishedDashboardSite();
    app()->tag([ContactFormFirstRunTaskContributor::class], FirstRunTaskContributor::TAG);

    $state = ResolveFirstRunStateAction::run(auth()->user());

    expect($state->isFirstRun)->toBeTrue()
        ->and(ContactFormFirstRunTaskContributor::$lastContext?->siteId)->toBe($draft->id)
        ->and(ContactFormFirstRunTaskContributor::$lastContext?->homePageId)->toBe($draftHome->id)
        ->and(resolve(SitePublicationMilestones::class)->publishedSiteIds())->toBe([$live->id]);
});

it('follows the site the work-first dashboard is scoped to', function (): void {
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $this->actingAsAdmin();
    $user = $this->authenticatedUser();
    $live = Site::factory()->createOne();
    Page::factory()->home()->site($live)->createOne(['visible_from' => now()->subMonth()]);
    [$draft] = unpublishedDashboardSite();
    $store = resolve(AdminWorkspacePreferenceStore::class);

    $store->saveDashboardScope($user, new DashboardScopePreferenceData($live->id, null));

    expect(ResolveFirstRunStateAction::run($user->refresh())->isFirstRun)->toBeFalse();

    $store->saveDashboardScope($user, new DashboardScopePreferenceData($draft->id, null));

    expect(ResolveFirstRunStateAction::run($user->refresh())->isFirstRun)->toBeTrue();
});

it('places recent updates only when the dashboard is configured with them', function (): void {
    $this->actingAsAdmin();
    unpublishedDashboardSite();
    $dashboard = new CapellDashboard;

    expect($dashboard->getWidgets())->toContain(RecentlyUpdatedWidget::class);

    $regions = new ReflectionMethod(CapellDashboard::class, 'firstRunRegions')->invoke($dashboard, [FirstRunChecklistFilamentWidget::class, NeedsAttentionFilamentWidget::class]);
    throw_unless(is_array($regions), LogicException::class);
    $classes = [];
    foreach ($regions as $region) {
        $classes = [...$classes, ...firstRunRegionClasses($region)];
    }

    expect($classes)->toContain(FirstRunChecklistFilamentWidget::class)
        ->and($classes)->toContain(NeedsAttentionFilamentWidget::class)
        ->and($classes)->not->toContain(RecentlyUpdatedWidget::class);
});

it('keeps recent updates on the work-first layout once the site is live', function (): void {
    $this->actingAsAdmin();
    [$site] = unpublishedDashboardSite();

    expect(RecentlyUpdatedWidget::canView())->toBeTrue();

    RecordSitePublishedAction::run($site->id);
    nextDashboardRequest();

    expect(RecentlyUpdatedWidget::canView())->toBeFalse();

    config()->set('capell-admin.dashboard.work_first.enabled', true);

    expect(RecentlyUpdatedWidget::canView())->toBeTrue();
});

/** @return list<string> */
function firstRunRegionClasses(mixed $node): array
{
    if ($node instanceof Filament\Schemas\Components\Livewire) {
        return [$node->getComponent()];
    }

    if (! $node instanceof Component) {
        return [];
    }

    $children = $node->getDefaultChildComponents();
    $children = $children instanceof Schema ? $children->getComponents() : $children;

    $classes = [];
    foreach ($children as $child) {
        $classes = [...$classes, ...firstRunRegionClasses($child)];
    }

    return $classes;
}

it('adds package tasks before Publish your site and ignores keys already in use', function (): void {
    $this->actingAsAdmin();
    [$site, $home] = unpublishedDashboardSite();
    app()->tag([ContactFormFirstRunTaskContributor::class], FirstRunTaskContributor::TAG);

    $state = ResolveFirstRunStateAction::run(auth()->user());

    expect(array_map(static fn ($task): string => $task->key, $state->tasks))
        ->toBe(['site', 'homepage', 'logo', 'address', 'contact-form', 'publish'])
        ->and($state->tasks[2]->title)->toBe(__('capell-admin::dashboard.first_run_task_logo_title'))
        ->and(ContactFormFirstRunTaskContributor::$lastContext?->siteId)->toBe($site->id)
        ->and(ContactFormFirstRunTaskContributor::$lastContext?->homePageId)->toBe($home->id);

    Livewire::test(FirstRunChecklistFilamentWidget::class)
        ->assertSeeInOrder(['Check the contact form', __('capell-admin::dashboard.first_run_task_publish_title')])
        ->assertSee(__('capell-admin::dashboard.first_run_description', ['done' => 1, 'total' => 6]))
        ->assertDontSee('Check the contact form again');
});

it('reports a failing task contributor and still shows the checklist', function (): void {
    Exceptions::fake();
    $this->actingAsAdmin();
    unpublishedDashboardSite();
    app()->tag([FailingFirstRunTaskContributor::class, ContactFormFirstRunTaskContributor::class], FirstRunTaskContributor::TAG);

    $keys = array_map(static fn ($task): string => $task->key, ResolveFirstRunStateAction::run(auth()->user())->tasks);

    expect($keys)->toBe(['site', 'homepage', 'logo', 'address', 'contact-form', 'publish']);
    Exceptions::assertReported(RuntimeException::class);
});

it('puts the checklist first on the work-first dashboard during first run', function (): void {
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $this->actingAsAdmin();
    unpublishedDashboardSite();

    Livewire::test(CapellDashboard::class)
        ->assertOk()
        ->assertSeeLivewire(FirstRunChecklistFilamentWidget::class);
});
