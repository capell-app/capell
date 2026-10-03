<?php

declare(strict_types=1);

use Capell\Admin\Actions\Reports\BuildPublicRenderSafetyReportAction;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Policies\LayoutPolicy;
use Capell\Admin\Support\Loader\SiteLoader;
use Capell\Admin\Support\MediaScope;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\PublicRenderContractEvent;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Links\PageLinkableContentProvider;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('denies legacy site queries without an actor even with the old opt out', function (): void {
    Site::factory()->create();
    expect(SiteScope::applyForCurrentActor(Site::query(), 'id', denyWhenMissingActor: false)->count())->toBe(0);
});

it('denies shared layout resource queries without an actor', function (): void {
    Layout::factory()->create(['site_id' => null]);

    expect(LayoutResource::getEloquentQuery()->count())->toBe(0);
});

it('denies dedicated layout editing abilities on a foreign layout', function (string $ability, string $permission): void {
    $assignedSite = Site::factory()->create();
    $foreignLayout = Layout::factory()->for(Site::factory()->create())->create();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assignedSite->getKey()]);
    Permission::findOrCreate($permission);
    $actor->givePermissionTo($permission);

    expect((new LayoutPolicy)->{$ability}($actor, $foreignLayout))->toBeFalse();
})->with([
    ['editContent', 'EditContent:Layout'],
    ['editLayout', 'EditLayout:Layout'],
]);

it('restricts render safety metrics and findings to accessible page attribution', function (): void {
    $assignedSite = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $assignedPage = Page::factory()->site($assignedSite)->create();
    $foreignPage = Page::factory()->site($foreignSite)->create();
    foreach ([[$assignedPage, 'visible'], [$foreignPage, 'foreign']] as [$page, $marker]) {
        PublicRenderContractEvent::query()->create([
            'result' => 'failed', 'page_id' => $page->getKey(), 'matched_marker' => $marker,
        ]);
    }

    PublicRenderContractEvent::query()->create(['result' => 'failed', 'matched_marker' => 'unattributed']);
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assignedSite->getKey()]);

    test()->actingAs($actor);

    $snapshot = BuildPublicRenderSafetyReportAction::run();
    $metrics = collect($snapshot->metrics)->pluck('value', 'label');

    expect($metrics[__('capell-admin::reports.public_render_safety_metric_events')])->toBe(1)
        ->and($snapshot->findings)->toHaveCount(1)
        ->and($snapshot->toJson())->toContain('visible')
        ->not->toContain('foreign')->not->toContain('unattributed');
});

it('denies render safety events without an actor and preserves global events', function (): void {
    PublicRenderContractEvent::query()->create(['result' => 'failed', 'matched_marker' => 'global']);

    expect(BuildPublicRenderSafetyReportAction::run()->findings)->toBe([]);

    test()->actingAsAdmin();

    expect(BuildPublicRenderSafetyReportAction::run()->findings)->toHaveCount(1);
});

it('does not reuse another actors site loader cache', function (): void {
    $assigned = Site::factory()->create();
    Site::factory()->create();
    test()->actingAsAdmin();
    expect(SiteLoader::getTotalSites())->toBe(2);
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assigned->getKey()]);

    test()->actingAs($actor);

    expect(SiteLoader::all()->modelKeys())->toBe([$assigned->getKey()])
        ->and(SiteLoader::getTotalSites())->toBe(1);
});

it('denies foreign link options supplied to the core authoring provider', function (): void {
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create();
    $foreign->load('siteDomains');
    PageUrl::factory()->create(['site_id' => $foreign->getKey(), 'type' => null]);
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assigned->getKey()]);

    test()->actingAs($actor);

    expect((new PageLinkableContentProvider)->options((int) $foreign->getKey()))->toBeEmpty();
});

it('shares layout access with media listings for authenticated actors without site grants', function (): void {
    $shared = Layout::factory()->create(['site_id' => null]);
    $media = Media::factory()->model($shared)->create();
    $translation = Translation::factory()->translatable($shared)->create();
    $translatedMedia = Media::factory()->model($translation)->create();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect();

    test()->actingAs($actor);

    expect(MediaScope::applyForCurrentActor(Media::query())->pluck('id')->all())->toEqualCanonicalizing([$media->getKey(), $translatedMedia->getKey()]);
});
