<?php

declare(strict_types=1);

use Capell\Admin\Actions\ValidateForceDeleteAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Resources\Blueprints\Pages\ManageBlueprints;
use Capell\Admin\Filament\Resources\Languages\Pages\ManageLanguages;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Admin\Filament\Resources\Sites\RelationManagers\SiteDomainsRelationManager;
use Capell\Admin\Filament\Resources\Themes\Pages\ManageThemes;
use Capell\Core\Actions\ContentGraph\RebuildContentGraphForModelAction;
use Capell\Core\Actions\PageDeletedAction;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Events\PageDeleted;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Tests\Fixtures\Models\User;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    test()->actingAsUser();
    Blueprint::factory()->theme()->default()->createOne();
    if (DB::connection()->getDriverName() === 'sqlite') {
        expect((int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
    }
});

/** Ordinary authors exercise the real generated permissions, never the admin bypass. */
function grantForceDeletionTestPermissions(): void
{
    $actor = test()->authenticatedUser();
    $actor->assignedSiteIds = Site::withTrashed()->pluck('id');
    foreach (ResourceEnum::cases() as $resource) {
        foreach (['view_any', 'view', 'force_delete', 'force_delete_any'] as $affix) {
            $actor->givePermissionTo(Permission::findOrCreate($resource->permission($affix), 'web'));
        }
    }

    expect($actor->isGlobalAdmin())->toBeFalse();
}

it('refuses a parent-only selection with an unauthorised nested descendant', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->createOne(['site_id' => $parent->site_id, 'blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $child->appendToNode($parent)->save();
    $grandchild = Page::factory()->createOne(['site_id' => $parent->site_id, 'blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $grandchild->appendToNode($child)->save();
    $parent->delete();

    test()->actingAsUser();
    test()->authenticatedUser()->assignedSiteIds = collect([$parent->site_id]);
    Gate::before(fn (User $user, string $ability, array $arguments): ?bool => match ($ability) {
        'viewAny', 'view', 'forceDeleteAny' => true,
        'forceDelete' => $arguments[0]->id !== $grandchild->id,
        default => null,
    });

    Livewire::test(ListPages::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$parent])
        ->selectTableRecords([$parent])
        ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk());

    expect(Page::withTrashed()->whereKey([$parent->id, $child->id, $grandchild->id])->count())->toBe(3);
});

it('refuses a parent-only selection with a retained dependency of its child', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->createOne(['site_id' => $parent->site_id, 'blueprint_id' => $parent->blueprint_id, 'layout_id' => $parent->layout_id]);
    $child->appendToNode($parent)->save();
    $dependent = Page::factory()->canonicalPage($child)->createOne();
    $dependent->delete();

    $parent->delete();
    grantForceDeletionTestPermissions();

    Livewire::test(ListPages::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$parent])
        ->selectTableRecords([$parent])
        ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk());

    expect(Page::withTrashed()->whereKey([$parent->id, $child->id, $dependent->id])->count())->toBe(3);
});

it('preserves retained graph and foreign-key dependants through bulk deletion', function (string $dependency, bool $trashed): void {
    [$record, $dependent, $component, $column] = match ($dependency) {
        'theme-layout' => [$theme = Theme::factory()->createOne(), Layout::factory()->createOne(['theme_id' => $theme->id, 'site_id' => null]), ManageThemes::class, 'theme_id'],
        'language-url' => [$language = Language::factory()->createOne(), PageUrl::factory()->redirect()->createOne(['language_id' => $language->id, 'site_id' => Site::factory()->createOne()->id, 'pageable_type' => null, 'pageable_id' => null, 'is_manual' => true]), ManageLanguages::class, 'language_id'],
        'language-domain' => [$language = Language::factory()->createOne(), SiteDomain::factory()->createOne(['language_id' => $language->id, 'site_id' => Site::factory()->createOne()->id]), ManageLanguages::class, 'language_id'],
        'language-translation' => [$language = Language::factory()->createOne(), Translation::factory()->translatable(Page::factory()->createOne())->createOne(['language_id' => $language->id]), ManageLanguages::class, 'language_id'],
        'site-url' => [$site = Site::factory()->createOne(), PageUrl::factory()->redirect()->createOne(['site_id' => $site->id, 'pageable_type' => null, 'pageable_id' => null, 'is_manual' => true]), ListSites::class, 'site_id'],
        'site-draft' => [$site = Site::factory()->createOne(), EditorScratchDraft::query()->create(['site_id' => $site->id, 'user_id' => test()->authenticatedUser()->id, 'locale' => 'en', 'record_type' => ($draftPage = Page::factory()->createOne())->getMorphClass(), 'record_id' => $draftPage->id, 'context' => 'page-editor', 'payload' => ['content' => 'Retained author draft'], 'content_hash' => hash('sha256', 'Retained author draft'), 'saved_at' => now()]), ListSites::class, 'site_id'],
        'site-taxonomy' => [$site = Site::factory()->createOne(), Taxonomy::factory()->createOne(['site_id' => $site->id]), ListSites::class, 'site_id'],
        'page-reference' => [$page = Page::factory()->createOne(), PagePropertyValue::factory()->createOne(['referenced_page_id' => $page->id]), ListPages::class, 'referenced_page_id'],
        'term-page-reference' => [$page = Page::factory()->createOne(), TermPropertyValue::factory()->createOne(['referenced_page_id' => $page->id]), ListPages::class, 'referenced_page_id'],
        default => throw new InvalidArgumentException('Unknown retained dependency: ' . $dependency),
    };
    if (in_array($dependency, ['theme-layout', 'language-url'], true)) {
        RebuildContentGraphForModelAction::run($dependent);
        expect(ContentGraphEdge::query()->where('source_type', $dependent::class)->where('source_id', $dependent->id)->where('target_type', $record::class)->where('target_id', $record->id)->where('strength', ContentGraphEdgeStrength::Strong)->exists())->toBeTrue();
    }

    if ($trashed && ($dependent instanceof Layout || $dependent instanceof PageUrl || $dependent instanceof SiteDomain || $dependent instanceof Translation)) {
        $dependent->delete();
    }

    $record->delete();
    grantForceDeletionTestPermissions();

    Livewire::test($component)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$record])
        ->selectTableRecords([$record])
        ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk());

    expect($record->fresh())->not->toBeNull()
        ->and($dependent->fresh())->not->toBeNull()
        ->and($dependent->fresh()?->getAttribute($column))->toBe($record->id);
})->with(['theme-layout', 'language-url', 'language-domain', 'language-translation', 'site-url', 'site-draft', 'site-taxonomy', 'page-reference', 'term-page-reference'])->with(['active' => false, 'trashed' => true]);

it('allows ordinary users with the generated force-delete permission and denies those without it', function (string $model): void {
    [$record, $component, $resource] = match ($model) {
        'blueprint' => [Blueprint::factory()->page()->createOne(), ManageBlueprints::class, ResourceEnum::Blueprint],
        'theme' => [Theme::factory()->createOne(), ManageThemes::class, ResourceEnum::Theme],
        'language' => [Language::factory()->createOne(), ManageLanguages::class, ResourceEnum::Language],
        'domain' => [SiteDomain::factory()->createOne(), SiteDomainsRelationManager::class, ResourceEnum::Site],
        default => throw new InvalidArgumentException('Unknown policy fixture: ' . $model),
    };
    $record->delete();
    test()->actingAsUser();
    $actor = test()->authenticatedUser();
    $actor->assignedSiteIds = $record instanceof SiteDomain ? collect([$record->site_id]) : collect();
    expect($actor->isGlobalAdmin())->toBeFalse()
        ->and(Gate::allows('forceDelete', $record))->toBeFalse();

    foreach (['view_any', 'view', 'force_delete_any', 'update'] as $affix) {
        $actor->givePermissionTo(Permission::findOrCreate($resource->permission($affix), 'web'));
    }

    $parameters = $record instanceof SiteDomain ? ['ownerRecord' => $record->site, 'pageClass' => EditSite::class] : [];
    Livewire::test($component, $parameters)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$record])
        ->selectTableRecords([$record])
        ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk())
        ->assertForbidden();
    expect($record->fresh())->not->toBeNull();
    $actor->givePermissionTo(Permission::findOrCreate($resource->permission('force_delete'), 'web'));
    expect(Gate::allows('forceDelete', $record))->toBeTrue();
    if ($record instanceof SiteDomain) {
        $foreign = SiteDomain::factory()->createOne();
        expect(Gate::allows('forceDelete', $foreign))->toBeFalse();
    }

    Livewire::test($component, $parameters)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$record])
        ->selectTableRecords([$record])
        ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk())
        ->assertSuccessful();

    expect($record->fresh())->toBeNull();
})->with(['blueprint', 'theme', 'language', 'domain']);

it('completes Pages bulk permanent deletion and runs every per-record side effect', function (): void {
    $pages = Page::factory()->count(2)->create();
    foreach ($pages as $page) {
        $url = PageUrl::factory()->page($page)->createOne(['site_id' => $page->site_id]);
        RebuildContentGraphForModelAction::run($url);
        PagePropertyValue::factory()->createOne(['site_id' => $page->site_id, 'page_id' => $page->id, 'referenced_page_id' => $page->id]);
    }

    $pages->each->delete();
    grantForceDeletionTestPermissions();
    Event::fake([PageDeleted::class]);
    PageDeletedAction::partialMock()->shouldReceive('handle')->twice()->passthru();

    Livewire::test(ListPages::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords($pages)
        ->selectTableRecords($pages)
        ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk())
        ->assertSuccessful();

    expect(Page::withTrashed()->whereKey($pages->modelKeys())->count())->toBe(0)
        ->and(PageUrl::withTrashed()->whereIn('pageable_id', $pages->modelKeys())->count())->toBe(0);
    expect(PagePropertyValue::query()->whereIn('page_id', $pages->modelKeys())->count())->toBe(0);
    foreach ($pages as $page) {
        Event::assertDispatched(PageDeleted::class, fn (PageDeleted $event): bool => $event->page->is($page));
    }
});

it('protects retained trashed strong graph dependants of media in the shared validator', function (): void {
    $page = Page::factory()->createOne();
    $media = Media::factory()->model(Page::factory()->createOne())->createOne();
    ContentGraphEdge::query()->create([
        'source_type' => Page::class, 'source_id' => $page->id,
        'target_type' => Media::class, 'target_id' => $media->id,
        'kind' => ContentGraphEdgeKind::UsesMedia,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/core', 'site_id' => $page->site_id,
    ]);
    $page->delete();
    $media->delete();
    grantForceDeletionTestPermissions();
    expect(ValidateForceDeleteAction::run($media, null))->toBeFalse();

    expect($media->fresh())->not->toBeNull()
        ->and($page->fresh()?->trashed())->toBeTrue();
});
