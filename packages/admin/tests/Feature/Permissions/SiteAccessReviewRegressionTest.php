<?php

declare(strict_types=1);

use Capell\Admin\Actions\Activity\BuildActivityChangeSetAction;
use Capell\Admin\Actions\Activity\DeleteActivityLogAction;
use Capell\Admin\Data\Activity\ActivityRevertSelectionData;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Pages\PageRelationshipCountsData;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Filament\Concerns\HasRelationManagerBadge;
use Capell\Admin\Filament\Concerns\Validate\LayoutValidation;
use Capell\Admin\Filament\Concerns\Validate\PageValidation;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Activities\ActivityResource;
use Capell\Admin\Filament\Resources\Activities\Tables\ActivitiesTable;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Widgets\Dashboard\RecentActivityFilamentWidget;
use Capell\Admin\Policies\PagePolicy;
use Capell\Admin\Support\Activity\DefaultActivityRevertHandler;
use Capell\Admin\Support\Activity\EventSourcedActivityRevertHandler;
use Capell\Admin\Support\Agent\AgentAdminAuthorization;
use Capell\Admin\Support\Agent\AgentPageDraftSaveTool;
use Capell\Admin\Support\Agent\AgentPageTermsWriteTool;
use Capell\Admin\Support\DashboardReports\NullActivityTrailQueryProvider;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\ContentLock;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\PageWorkflowState;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Tests\Fixtures\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    test()->actingAsAdmin();
    $this->globalActor = test()->authenticatedUser();
    $this->alpha = Site::factory()->create();
    $this->beta = Site::factory()->create();
    $this->actor = User::factory()->create();
    $this->actor->assignedSiteIds = collect([(int) $this->alpha->id]);
});

/** @param list<string> $permissions */
function reviewActorWithPermissions(User $actor, array $permissions): void
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
        $actor->givePermissionTo($permission);
    }

    test()->actingAs($actor);
}

function reviewLoggedActivity(?Spatie\Activitylog\Contracts\Activity $activity): Activity
{
    throw_unless($activity instanceof Activity, LogicException::class, 'Expected a persisted activity model');

    return $activity;
}

/** @param list<string> $paths */
function reviewSelection(Activity $activity, array $paths, ?Page $page = null): ActivityRevertSelectionData
{
    return new ActivityRevertSelectionData(
        activityId: $activity->id,
        selectedPaths: $paths,
        beforeValues: [],
        actorId: auth()->id(),
        subjectMorphType: $page?->getMorphClass(),
        subjectClass: $page instanceof Page ? Page::class : null,
        subjectId: $page?->id,
        stableIdentifier: null,
        workspaceId: null,
    );
}

it('scopes default activity listing and workspace badge and recent widget by subject owner', function (): void {
    $alpha = Page::factory()->site($this->alpha)->withTranslations()->create();
    $beta = Page::factory()->site($this->beta)->withTranslations()->create();
    $sharedLayout = Layout::factory()->create(['site_id' => null]);
    $mediaTranslation = Translation::factory()->translatable(Media::factory()->model($alpha)->create())->create();
    Activity::query()->delete();
    $own = reviewLoggedActivity(activity()->performedOn($alpha)->withProperties(['workspace_id' => 7])->log('alpha'));
    $ownTranslation = reviewLoggedActivity(activity()->performedOn($alpha->translations->first())->log('alpha translation'));
    $ownMediaTranslation = reviewLoggedActivity(activity()->performedOn($mediaTranslation)->log('alpha media translation'));
    $shared = reviewLoggedActivity(activity()->performedOn($sharedLayout)->log('shared layout'));
    activity()->performedOn($beta)->withProperties(['workspace_id' => 7])->log('beta secret');
    activity()->performedOn($beta->translations->first())->log('beta translation secret');
    activity()->log('unattributed');
    test()->actingAs($this->actor);
    expect((new NullActivityTrailQueryProvider)->build()->orderBy('id')->pluck('id')->all())->toBe([$own->id, $ownTranslation->id, $ownMediaTranslation->id, $shared->id]);
    expect(ActivityResource::getNavigationBadge())->toBe('1');

    $method = new ReflectionMethod(RecentActivityFilamentWidget::class, 'activityQuery');
    expect($method->invoke(new RecentActivityFilamentWidget)->reorder('id')->pluck('id')->all())->toBe([$own->id, $ownTranslation->id, $ownMediaTranslation->id, $shared->id]);
    test()->actingAs($this->globalActor);
    expect((new NullActivityTrailQueryProvider)->build()->count())->toBe(7);
    auth()->logout();
    expect((new NullActivityTrailQueryProvider)->build()->exists())->toBeFalse();
});

it('authorises activity details at action time after its subject changes sites', function (): void {
    $page = Page::factory()->site($this->alpha)->create();
    $activity = reviewLoggedActivity(activity()->performedOn($page)->log('alpha'));
    test()->actingAs($this->actor);
    expect(BuildActivityChangeSetAction::run($activity))->not->toBeNull();
    $page->forceFill(['site_id' => $this->beta->id])->saveQuietly();
    expect(fn () => BuildActivityChangeSetAction::run($activity))->toThrow(AuthorizationException::class);
    $action = ActivitiesTable::viewDetailsAction()->record($activity);
    expect(fn (): View|Htmlable|null => $action->getModalContent())->toThrow(AuthorizationException::class);
});

it('denies foreign activity deletion while permitting assigned activity deletion', function (): void {
    $own = reviewLoggedActivity(activity()->performedOn(Page::factory()->site($this->alpha)->create())->log('alpha'));
    $foreign = reviewLoggedActivity(activity()->performedOn(Page::factory()->site($this->beta)->create())->log('beta'));
    reviewActorWithPermissions($this->actor, [CapellPermission::DeleteActivityLog->name()]);
    expect(DeleteActivityLogAction::run($own))->toBeTrue();
    expect(fn () => DeleteActivityLogAction::run($foreign))->toThrow(AuthorizationException::class);
    expect($foreign->fresh())->not->toBeNull();
});

it('requires global activity ownership even when calling the default revert handler directly', function (): void {
    $language = Language::factory()->create(['name' => 'French']);
    $activity = reviewLoggedActivity(activity()->performedOn($language)->event('updated')->withProperties([
        'old' => ['name' => 'Francais'], 'attributes' => ['name' => 'French'],
    ])->log('global language metadata'));
    reviewActorWithPermissions($this->actor, [CapellPermission::RevertActivityLog->name()]);
    $handler = new DefaultActivityRevertHandler;
    expect($handler->revert(reviewSelection($activity, ['name']))->successful)->toBeFalse();
    expect($language->refresh()->name)->toBe('French');

    test()->actingAs($this->globalActor);
    expect($handler->revert(reviewSelection($activity, ['name']))->successful)->toBeTrue();
    expect($language->refresh()->name)->toBe('Francais');
});

it('denies default revert into foreign ownership or foreign references', function (string $field): void {
    $layout = Layout::factory()->create(['site_id' => $this->alpha->id]);
    $foreignLayout = Layout::factory()->create(['site_id' => $this->beta->id]);
    $page = Page::factory()->site($this->alpha)->layout($layout)->create();
    $foreignPage = Page::factory()->site($this->beta)->create();
    $subject = $field === 'site_id' ? $layout : $page;
    $old = match ($field) {
        'site_id' => $this->beta->id, 'layout_id' => $foreignLayout->id, 'parent_id' => $foreignPage->id,
        'meta' => ['canonical_pageable_type' => $foreignPage->getMorphClass(), 'canonical_pageable_id' => $foreignPage->id],
        default => throw new LogicException('Unexpected reference field')
    };
    $activity = reviewLoggedActivity(activity()->performedOn($subject)->event('updated')->withProperties([
        'old' => [$field => $old], 'attributes' => [$field => $subject->getAttribute($field)],
    ])->log('moved from beta'));
    reviewActorWithPermissions($this->actor, [CapellPermission::RevertActivityLog->name()]);
    $before = $subject->fresh()->getRawOriginal();
    expect((new DefaultActivityRevertHandler)->revert(reviewSelection($activity, [$field]))->successful)->toBeFalse();
    expect($subject->refresh()->getRawOriginal())->toBe($before);
})->with(['site_id', 'layout_id', 'parent_id', 'meta']);

it('denies event sourced revert into foreign historical ownership and references', function (string $field): void {
    $this->freezeTime();

    $layout = Layout::factory()->create(['site_id' => $this->alpha->id]);
    $foreignLayout = Layout::factory()->create(['site_id' => $this->beta->id]);
    $foreignParent = Page::factory()->site($this->beta)->create();
    $page = Page::factory()->site($this->alpha)->layout($layout)->withTranslations()->create();
    $original = $page->getAttribute($field);
    $foreign = match ($field) {
        'site_id' => $this->beta->id, 'layout_id' => $foreignLayout->id, 'parent_id' => $foreignParent->id,
        'meta' => ['canonical_pageable_type' => $foreignParent->getMorphClass(), 'canonical_pageable_id' => $foreignParent->id],
        default => throw new LogicException('Unexpected reference field')
    };
    $page->forceFill([$field => $foreign])->save();
    $activity = reviewLoggedActivity(activity()->performedOn($page)->event('updated')->log('historical beta state'));
    $activity->forceFill(['created_at' => now()->subSecond()])->save();
    // Revisions use their occurrence time to select the activity's target.
    PageRevision::query()->where('page_uuid', $page->uuid)->update(['occurred_at' => now()->subSeconds(2)]);
    $page->forceFill([$field => $original])->saveQuietly();
    reviewActorWithPermissions($this->actor, [CapellPermission::RollbackPage->name()]);
    $before = $page->fresh()->getRawOriginal();
    $result = resolve(EventSourcedActivityRevertHandler::class)->revert(reviewSelection($activity, [], $page));
    expect($result->successful)->toBeFalse()
        ->and($result->messageKey)->toBe('capell-admin::event-sourcing.rollback_forbidden');
    expect($page->refresh()->getRawOriginal())->toBe($before);
})->with(['site_id', 'layout_id', 'parent_id', 'meta']);

it('denies every page record ability after the page moves out of the actors site', function (string $ability, string $permission): void {
    $page = Page::factory()->site($this->alpha)->create();
    reviewActorWithPermissions($this->actor, [$permission]);
    $policy = resolve(PagePolicy::class);
    expect($policy->{$ability}($this->actor, $page))->toBeTrue();
    $page->forceFill(['site_id' => $this->beta->id])->saveQuietly();
    expect($policy->{$ability}($this->actor, $page->fresh()))->toBeFalse();
})->with([
    ['view', 'View:Page'], ['update', 'Update:Page'], ['delete', 'Delete:Page'], ['restore', 'Restore:Page'],
    ['forceDelete', 'ForceDelete:Page'], ['replicate', 'Replicate:Page'], ['editContent', 'EditContent:Page'],
    ['editLayout', 'EditLayout:Page'], ['export', CapellPermission::ExportPage->name()],
]);

it('counts shared layout relation badges through the accessible relation', function (): void {
    $layout = Layout::factory()->create(['site_id' => null]);
    Page::factory()->site($this->alpha)->layout($layout)->create();
    Page::factory()->site($this->beta)->layout($layout)->create();
    test()->actingAs($this->actor);
    $manager = new class
    {
        use HasRelationManagerBadge;

        protected static string $relationship = 'pages';
    };
    expect($manager::getBadge($layout, ''))->toBe('1');
    expect($layout->relationLoaded('pages'))->toBeFalse();
});

it('blocks shared layout deletion without disclosing foreign page totals', function (): void {
    $layout = Layout::factory()->create(['site_id' => null]);
    Page::factory()->site($this->alpha)->layout($layout)->create();
    Page::factory()->site($this->beta)->layout($layout)->count(4)->create();
    test()->actingAs($this->actor);
    resolve(Translator::class)->addLines(['message.layout_not_deletable_info' => 'Used by :count pages'], 'en', 'capell-admin');
    session()->forget('filament.notifications');
    $validator = new class implements ValidatesDelete
    {
        use LayoutValidation;
    };
    expect($validator->validateDelete($layout))->toBeFalse();
    $notification = collect(session('filament.notifications'))->last();
    expect($notification['body'])->toBe('Used by 1 pages');
    expect($layout->fresh())->not->toBeNull()->and($layout->relationLoaded('pages'))->toBeFalse();
});

it('blocks canonical page deletion without disclosing foreign variation totals', function (): void {
    $page = Page::factory()->site($this->alpha)->create();
    $meta = ['canonical_pageable_type' => $page->getMorphClass(), 'canonical_pageable_id' => $page->id];
    Page::factory()->site($this->alpha)->create(['meta' => $meta]);
    Page::factory()->site($this->beta)->count(3)->create(['meta' => $meta]);
    test()->actingAs($this->actor);
    resolve(Translator::class)->addLines(['message.canonical_page_not_deletable' => 'Used by :count variations'], 'en', 'capell-admin');
    session()->forget('filament.notifications');
    $validator = new class implements ValidatesDelete
    {
        use PageValidation;
    };
    expect($validator->validateDelete($page))->toBeFalse();
    expect(collect(session('filament.notifications'))->last()['body'])->toBe('Used by 1 variations');
});

it('denies a mounted editor update when its page moves out of scope before hydration', function (): void {
    $page = Page::factory()->site($this->alpha)->withTranslations()->create();
    reviewActorWithPermissions($this->actor, ['ViewAny:Page', 'View:Page', 'Update:Page']);
    $editor = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])->assertSuccessful();
    $page->forceFill(['site_id' => $this->beta->id])->saveQuietly();
    $editor->set('data.name', 'A stale editor write');

    // Filament 5.10 re-resolves hydrated records through the scoped resource query.
    // Earlier versions retain the record and deny it at the policy check instead.
    if (new ReflectionClass(EditRecord::class)->hasProperty('hasResolvedRecordForRequest')) {
        $editor->assertNotFound();
    } else {
        $editor->assertForbidden();
    }

    expect($page->refresh()->name)->not->toBe('A stale editor write');
    expect(EditorScratchDraft::query()->where('record_id', $page->id)->count())->toBe(0);
});

it('counts canonical backlinks in the editor only within the actors sites', function (): void {
    $page = Page::factory()->site($this->alpha)->withTranslations()->create();
    $meta = ['canonical_pageable_type' => $page->getMorphClass(), 'canonical_pageable_id' => $page->id];
    Page::factory()->site($this->alpha)->create(['meta' => $meta]);
    Page::factory()->site($this->beta)->count(3)->create(['meta' => $meta]);
    reviewActorWithPermissions($this->actor, ['ViewAny:Page', 'View:Page', 'Update:Page']);
    $editor = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])->assertSuccessful();
    expect($editor->instance()->getRecord()->getAttribute('canonical_pages_count'))->toBe(1);
});

it('counts hierarchy children only within assigned sites including a preloaded foreign child', function (): void {
    $parent = Page::factory()->site($this->alpha)->create();
    $own = Page::factory()->site($this->alpha)->create();
    $foreign = Page::factory()->site($this->beta)->create();
    $own->forceFill(['parent_id' => $parent->id])->saveQuietly();
    $foreign->forceFill(['parent_id' => $parent->id])->saveQuietly();
    test()->actingAs($this->actor);

    expect(PageRelationshipCountsData::fromPage($parent)->childrenCount)->toBe(1);
    $parent->load('children');
    expect($parent->children)->toHaveCount(2)
        ->and(PageRelationshipCountsData::fromPage($parent)->childrenCount)->toBe(1);
});

it('scopes draft translations for the explicit invocation actor without request authentication', function (): void {
    $page = Page::factory()->site($this->alpha)->withTranslations()->create();
    $translation = $page->translations->firstOrFail();
    reviewActorWithPermissions($this->actor, ['Update:Page']);
    $invocation = new AgentAdminToolInvocationData(
        tool: 'admin.page.draft.save',
        payload: ['page_id' => $page->id, 'fields' => ['name' => 'Draft only'], 'translations' => [['id' => $translation->id, 'title' => 'Draft title']]],
        siteId: $this->alpha->id,
        user: $this->actor,
    );
    auth()->logout();
    $tool = new AgentPageDraftSaveTool(new AgentAdminAuthorization);
    $tool->authorize($invocation);

    expect($tool->preview($invocation)->data['after']['translations'][0]['id'])->toBe($translation->id);
    expect(auth()->user())->toBeNull();
});

it('scopes current terms for the explicit invocation actor without request authentication', function (): void {
    $page = Page::factory()->site($this->alpha)->create();
    $term = Term::factory()->create(['taxonomy_id' => Taxonomy::factory()->create(['site_id' => $this->alpha->id])->id]);
    $page->terms()->attach($term);
    reviewActorWithPermissions($this->actor, ['Update:Page']);
    $invocation = new AgentAdminToolInvocationData(
        tool: 'admin.page.terms.write',
        payload: ['page_id' => $page->id, 'term_ids' => [$term->id]],
        siteId: $this->alpha->id,
        user: $this->actor,
    );
    auth()->logout();
    $tool = new AgentPageTermsWriteTool(new AgentAdminAuthorization);
    $tool->authorize($invocation);

    expect($tool->preview($invocation)->data['before_term_ids'])->toBe([$term->id]);
    expect(auth()->user())->toBeNull();
});

it('scopes indirectly owned translations revisions workflow locks and term values consistently', function (): void {
    $ownPage = Page::factory()->site($this->alpha)->withTranslations()->create();
    $foreignPage = Page::factory()->site($this->beta)->withTranslations()->create();
    $pairs = [[$ownPage->translations->first(), $foreignPage->translations->first()]];
    foreach ([PageRevision::class, PageWorkflowState::class] as $model) {
        $records = [];
        foreach ([$ownPage, $foreignPage] as $page) {
            $attributes = $model === PageRevision::class
                ? ['page_uuid' => $page->uuid, 'version' => 99, 'summary' => 'ownership probe', 'occurred_at' => now()]
                : ['page_uuid' => $page->uuid, 'status' => 'draft', 'aggregate_version' => 99];
            $records[] = $model::query()->updateOrCreate(['page_uuid' => $page->uuid], $attributes);
        }

        $pairs[] = $records;
    }

    $records = [];
    foreach ([$ownPage, $foreignPage] as $page) {
        $records[] = ContentLock::query()->create([
            'user_id' => $this->actor->id, 'model_type' => $page->getMorphClass(), 'model_id' => $page->id, 'expires_at' => now()->addMinutes(15),
        ]);
    }

    $pairs[] = $records;
    $termValues = [];
    foreach ([$this->alpha, $this->beta] as $site) {
        $taxonomy = Taxonomy::factory()->create(['site_id' => $site->id]);
        $term = Term::factory()->create(['taxonomy_id' => $taxonomy->id]);
        $termValues[] = TermPropertyValue::factory()->create(['term_id' => $term->id]);
    }

    $pairs[] = $termValues;
    $access = SiteAccess::forActor($this->actor);
    foreach ($pairs as [$own, $foreign]) {
        expect($access->canUseRecord($own))->toBeTrue()->and($access->canUseRecord($foreign))->toBeFalse();
        expect($access->query($own::class)->whereKey($own->id)->exists())->toBeTrue()
            ->and($access->query($foreign::class)->whereKey($foreign->id)->exists())->toBeFalse();
    }
});

it('keeps asset owner policy and query access on the same finite ownership graph', function (string $kind): void {
    $page = Page::factory()->site($this->alpha)->create();
    $media = Media::factory()->model($page)->create();
    $translation = Translation::factory()->translatable($media)->create();
    $record = $kind === 'media'
        ? Media::factory()->model($translation)->create()
        : AssetAttachment::factory()->related($media)->create(['asset_id' => $page->id]);
    $access = SiteAccess::forActor($this->actor);
    expect($access->query($record::class)->whereKey($record->id)->exists())->toBeFalse();
    expect($access->canUseRecord($record))->toBeFalse();
})->with(['media', 'attachment']);

it('checks registered indirect owners consistently and ignores stale loaded ownership', function (): void {
    $map = Relation::morphMap();
    Relation::morphMap(['review-term-owner' => Term::class]);
    try {
        $taxonomy = Taxonomy::factory()->create(['site_id' => $this->alpha->id]);
        $term = Term::factory()->create(['taxonomy_id' => $taxonomy->id]);
        $translation = Translation::factory()->translatable($term)->create();
        $term->load('taxonomy');
        $translation->load('translatable.taxonomy');
        $access = SiteAccess::forActor($this->actor);
        foreach ([$term, $translation] as $record) {
            expect($access->query($record::class)->whereKey($record->id)->exists())->toBeTrue();
            expect($access->canUseRecord($record))->toBeTrue();
        }

        $taxonomy->forceFill(['site_id' => $this->beta->id])->saveQuietly();
        foreach ([$term, $translation] as $record) {
            expect($access->query($record::class)->whereKey($record->id)->exists())->toBeFalse();
            expect($access->canUseRecord($record))->toBeFalse();
        }
    } finally {
        Relation::morphMap($map, merge: false);
    }
});
