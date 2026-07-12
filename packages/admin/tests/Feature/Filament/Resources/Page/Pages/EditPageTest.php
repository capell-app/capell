<?php

declare(strict_types=1);

use Capell\Admin\Actions\ExtractContentFromBlocksAction;
use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Contracts\Extenders\ResourceHeaderActionExtender;
use Capell\Admin\Enums\AdminFormActionPositionEnum;
use Capell\Admin\Filament\Components\Forms\Editor\ContentBuilder;
use Capell\Admin\Filament\Components\Forms\Page\ContentEditor;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\LandingPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\ResultsPageConfigurator;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\ContentLock;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\assertSoftDeleted;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

use Sinnbeck\DomAssertions\Asserts\AssertElement;
use Sinnbeck\DomAssertions\Asserts\BaseAssert;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('page');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

/**
 * @param  Pageable<Page>  $page
 * @param  Collection<int, int>  $languages
 */
function setupPage(Pageable $page, Collection $languages): void
{
    $languages->each(function (int $languageId) use ($page): void {
        $page->translations()->save(
            Translation::factory()->make([
                'language_id' => $languageId,
                'title' => Str::title($page->name . ' ' . $languageId),
            ]),
        );
    });

    $page->refresh();
}

function groupedHeaderAction(EditPage $component, string $actionName): Action
{
    $actionGroup = collect($component->getCachedHeaderActions())
        ->first(fn (object $action): bool => $action instanceof ActionGroup
            && ($action->getFlatActions()[$actionName] ?? null) instanceof Action);

    throw_unless($actionGroup instanceof ActionGroup, RuntimeException::class, 'Expected an EditPage header action group.');

    $action = $actionGroup->getFlatActions()[$actionName] ?? null;

    throw_unless($action instanceof Action, RuntimeException::class, sprintf('Expected [%s] header action.', $actionName));

    return $action;
}

test('can render page', function (): void {
    get(PageResource::getUrl('edit', [
        'record' => Page::factory()->createOne(),
    ]))
        ->assertSuccessful();
});

test('renders the typed Blocks page-body editor for a page-level content structure override', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()
        ->recycle($language)
        ->withTranslations($language, contentStructure: ContentStructure::Blocks)
        ->createOne([
            'content_structure_override' => ContentStructure::Blocks->value,
        ]);

    get(PageResource::getUrl('edit', [
        'record' => $page,
    ]))
        ->assertSuccessful()
        ->assertSee('fi-fo-builder', false)
        ->assertSee(__('capell-admin::button.add_content_block'))
        ->assertDontSee('layout-builder-visual-toolbar', false);
});

test('can render page when its layout has been deleted', function (): void {
    $page = Page::factory()->createOne();
    $page->layout->delete();

    // The layout was moved out of the header into the form, so a deleted layout no
    // longer surfaces a header health chip — the page must still render gracefully.
    get(PageResource::getUrl('edit', [
        'record' => $page,
    ]))
        ->assertSuccessful();
});

test('edit page hides authoring surface markup', function (bool $layoutEditable): void {
    Queue::fake();

    $type = Blueprint::factory()->page()->create([
        'meta' => [
            'layout_editable' => $layoutEditable,
        ],
    ]);
    $page = Page::factory()->type($type)->create();

    $response = get(PageResource::getUrl('edit', [
        'record' => $page,
    ]))->assertSuccessful();

    $response->assertDontSee('authoring-surface');
})->with([
    'enabled' => [true],
    'disabled' => [false],
]);

it('can retrieve data', function (): void {
    $page = Page::factory()->createOne();

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSchemaStateSet([
            'name' => $page->name,
            'blueprint_id' => $page->blueprint->getKey(),
            'layout_id' => $page->layout->getKey(),
            'site_id' => $page->site->getKey(),
        ]);
});

it('shows only the page url as a link in the edit page subheading', function (): void {
    $site = Site::factory()
        ->withTranslations(siteDomainData: [
            'scheme' => 'https',
            'domain' => 'example.test',
            'path' => null,
        ])
        ->createOne(['name' => 'Marketing <Site>']);
    $layout = Layout::factory()
        ->site($site)
        ->createOne(['name' => 'Landing <Layout>']);
    $page = Page::factory()
        ->site($site)
        ->for($layout)
        ->createOne(['name' => 'Context page']);

    PageUrl::factory()
        ->site($site)
        ->page($page)
        ->language($site->language)
        ->createOne(['url' => '/context-page']);

    $component = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->instance();

    $subheading = $component->getSubheading();

    expect($subheading)->toBeInstanceOf(Htmlable::class);
    assert($subheading instanceof Htmlable);

    // Site, Page type, and Layout were moved out of the header into the form, so
    // the subheading now surfaces only the URL — rendered as a clickable link.
    expect($subheading->toHtml())
        ->toContain(__('capell-admin::table.url'))
        ->toContain('https://example.test/context-page')
        ->toContain('<a href="https://example.test/context-page"')
        ->not->toContain(__('capell-admin::table.site'))
        ->not->toContain(__('capell-admin::generic.page_type'))
        ->not->toContain(__('capell-admin::table.layout'))
        ->not->toContain('/sites/' . $site->getKey())
        ->not->toContain('/layouts/' . $layout->getKey());
});

it('shows the draft preview action only for pending pages', function (): void {
    Route::get('/admin/preview/page/{page}', fn (): string => 'Preview')
        ->name('capell.admin.preview-page');

    $draft = Page::factory()->pending()->createOne();
    $published = Page::factory()->createOne(['visible_from' => now()->subDay()]);

    Livewire::test(EditPage::class, [
        'record' => $draft->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertActionVisible('previewDraftPage');

    Livewire::test(EditPage::class, [
        'record' => $published->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertActionHidden('previewDraftPage');
});

it('labels the primary save action as save for live published pages', function (): void {
    $page = Page::factory()->createOne([
        'visible_from' => now()->subDay(),
        'visible_until' => null,
    ]);

    $component = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->instance();

    /** @var Action|null $saveAction */
    $saveAction = collect($component->getCachedFormActions())
        ->first(fn (object $action): bool => $action instanceof Action && $action->getName() === 'save');

    expect($saveAction)->toBeInstanceOf(Action::class);
    assert($saveAction instanceof Action);

    expect(filamentText($saveAction->getLabel()))->toBe(__('capell-admin::button.save'));
});

it('labels the primary save action as save and publish for unpublished pages', function (): void {
    $page = Page::factory()->createOne([
        'visible_from' => now()->addDay(),
        'visible_until' => null,
    ]);

    $component = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->instance();

    /** @var Action|null $saveAction */
    $saveAction = collect($component->getCachedFormActions())
        ->first(fn (object $action): bool => $action instanceof Action && $action->getName() === 'save');

    expect($saveAction)->toBeInstanceOf(Action::class);
    assert($saveAction instanceof Action);

    expect(filamentText($saveAction->getLabel()))->toBe(__('capell-admin::button.save_and_publish'));
});

// Unpublish / cancel-scheduled-unpublish moved off the EditPage form into the
// standalone PublishStatusPanel Livewire component; that behaviour and its
// visibility gating are covered in PublishStatusPanelTest.

it('acquires a content lock when an editor opens a page', function (): void {
    $page = Page::factory()->createOne();

    get(PageResource::getUrl('edit', [
        'record' => $page,
    ]))
        ->assertSuccessful()
        ->assertSee('data-capell-content-lock-heartbeat', false);

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertActionHidden('take-over-content-lock');

    assertDatabaseHas(ContentLock::class, [
        'user_id' => test()->authenticatedUser()->getKey(),
        'model_type' => $page->getMorphClass(),
        'model_id' => $page->getKey(),
    ]);
});

it('refreshes an owned page content lock from the admin heartbeat endpoint', function (): void {
    $page = Page::factory()->createOne();

    Date::setTestNow('2026-05-31 10:00:00');

    ContentLock::query()->create([
        'user_id' => test()->authenticatedUser()->getKey(),
        'model_type' => $page->getMorphClass(),
        'model_id' => $page->getKey(),
        'expires_at' => Date::now()->addMinute(),
    ]);

    post(route('capell-admin.api.pages.content-lock.heartbeat', ['page' => $page]))
        ->assertSuccessful()
        ->assertJsonPath('expires_at', '2026-05-31T10:15:00+00:00');

    expect(ContentLock::query()->sole()->expires_at->toDateTimeString())->toBe('2026-05-31 10:15:00');

    Date::setTestNow();
});

it('does not take over another editor page content lock from the heartbeat endpoint', function (): void {
    $page = Page::factory()->createOne();
    $owner = test()->createUser(['name' => 'Ben']);

    Date::setTestNow('2026-05-31 10:00:00');

    ContentLock::query()->create([
        'user_id' => $owner->getKey(),
        'model_type' => $page->getMorphClass(),
        'model_id' => $page->getKey(),
        'expires_at' => Date::now()->addMinutes(15),
    ]);

    post(route('capell-admin.api.pages.content-lock.heartbeat', ['page' => $page]))
        ->assertConflict()
        ->assertJsonPath('message', __('capell-admin::message.content_lock_conflict'));

    expect(ContentLock::query()->sole()->user_id)->toBe($owner->getKey());

    Date::setTestNow();
});

it('releases only the current editors page content lock from the admin release endpoint', function (): void {
    $page = Page::factory()->createOne();
    $otherPage = Page::factory()->createOne();
    $owner = test()->createUser(['name' => 'Ben']);

    ContentLock::query()->create([
        'user_id' => test()->authenticatedUser()->getKey(),
        'model_type' => $page->getMorphClass(),
        'model_id' => $page->getKey(),
        'expires_at' => now()->addMinutes(15),
    ]);

    ContentLock::query()->create([
        'user_id' => $owner->getKey(),
        'model_type' => $otherPage->getMorphClass(),
        'model_id' => $otherPage->getKey(),
        'expires_at' => now()->addMinutes(15),
    ]);

    post(route('capell-admin.api.pages.content-lock.release', ['page' => $page]))
        ->assertSuccessful()
        ->assertJsonPath('released', true);

    assertDatabaseMissing(ContentLock::class, [
        'user_id' => test()->authenticatedUser()->getKey(),
        'model_type' => $page->getMorphClass(),
        'model_id' => $page->getKey(),
    ]);

    assertDatabaseHas(ContentLock::class, [
        'user_id' => $owner->getKey(),
        'model_type' => $otherPage->getMorphClass(),
        'model_id' => $otherPage->getKey(),
    ]);
});

it('warns and blocks saves while another editor has an active page lock', function (): void {
    $page = Page::factory()->withTranslations()->createOne(['name' => 'Original Name']);
    $owner = test()->createUser(['name' => 'Ben']);
    $otherEditor = test()->createUserWithRole('super_admin', ['name' => 'Other Editor']);

    Date::setTestNow('2026-05-31 10:00:00');

    ContentLock::query()->create([
        'user_id' => $owner->getKey(),
        'model_type' => $page->getMorphClass(),
        'model_id' => $page->getKey(),
        'expires_at' => Date::now()->addMinutes(15),
    ]);

    test()->actingAs($otherEditor);

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertNotified(__('capell-admin::message.content_lock_active', ['name' => 'Ben']))
        ->fillForm([
            'name' => 'Blocked Name',
        ])
        ->call('save')
        ->assertNotified(__('capell-admin::message.content_lock_active', ['name' => 'Ben']));

    expect($page->refresh()->name)->toBe('Original Name')
        ->and(ContentLock::query()->first()?->user_id)->toBe($owner->getKey());

    Date::setTestNow();
});

it('lets an editor explicitly take over an active page lock', function (): void {
    $page = Page::factory()->createOne();
    $owner = test()->createUser(['name' => 'Ben']);
    $otherEditor = test()->createUserWithRole('super_admin', ['name' => 'Other Editor']);

    Date::setTestNow('2026-05-31 10:00:00');

    ContentLock::query()->create([
        'user_id' => $owner->getKey(),
        'model_type' => $page->getMorphClass(),
        'model_id' => $page->getKey(),
        'expires_at' => Date::now()->addMinutes(15),
    ]);

    test()->actingAs($otherEditor);

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertActionVisible('take-over-content-lock')
        ->callAction('take-over-content-lock')
        ->assertHasNoActionErrors()
        ->assertNotified(__('capell-admin::message.content_lock_taken_over'));

    $lock = ContentLock::query()->sole();

    expect($lock->user_id)->toBe($otherEditor->getKey())
        ->and($lock->model_type)->toBe($page->getMorphClass())
        ->and($lock->model_id)->toBe($page->getKey())
        ->and($lock->expires_at->toDateTimeString())->toBe('2026-05-31 10:15:00');

    Date::setTestNow();
});

test('uses form actions below the edit form by default', function (): void {
    $page = Page::factory()->createOne(['visible_until' => null]);

    $livewire = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->assertSuccessful();

    $component = $livewire->instance();

    throw_unless($component instanceof EditPage, RuntimeException::class, 'Expected EditPage Livewire component instance.');

    $formActionNames = collect($component->getCachedFormActions())
        ->map(fn (object $action): ?string => filamentObjectName($action))
        ->filter()
        ->values()
        ->all();

    $headerActionNames = collect($component->getCachedHeaderActions())
        ->map(fn (object $action): ?string => $action instanceof ActionGroup ? 'actionGroup' : filamentObjectName($action))
        ->filter()
        ->values()
        ->all();

    expect($formActionNames)->toBe(['save', 'cancel'])
        ->and($headerActionNames)->not->toContain('save')
        ->and($headerActionNames)->not->toContain('cancel');
});

it('shows the frontend source map header action', function (): void {
    $page = Page::factory()->createOne();

    $livewire = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->assertSuccessful();

    $component = $livewire->instance();

    throw_unless($component instanceof EditPage, RuntimeException::class, 'Expected EditPage Livewire component instance.');

    $actionGroup = collect($component->getCachedHeaderActions())
        ->first(fn (object $action): bool => $action instanceof ActionGroup
            && ($action->getFlatActions()['frontendSourceMap'] ?? null) instanceof Action);

    assert($actionGroup instanceof ActionGroup);

    $frontendSourceMapAction = $actionGroup->getFlatActions()['frontendSourceMap'];

    expect($actionGroup)->not->toBeNull()
        ->and(collect($actionGroup->getFlatActions())->keys()->all())->toContain('frontendSourceMap')
        ->and($frontendSourceMapAction->getLabel())->toBe(__('capell-admin::generic.frontend_source_map'))
        ->and($frontendSourceMapAction->getTooltip())->toBe(__('capell-admin::generic.frontend_source_map_description'))
        ->and($frontendSourceMapAction->getModalDescription())->toBe(__('capell-admin::generic.frontend_source_map_description'));
});

it('shows the frontend resource diagnostics header action', function (): void {
    $page = Page::factory()->createOne();

    $livewire = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->assertSuccessful();

    $component = $livewire->instance();

    throw_unless($component instanceof EditPage, RuntimeException::class, 'Expected EditPage Livewire component instance.');

    $actionGroup = collect($component->getCachedHeaderActions())
        ->first(fn (object $action): bool => $action instanceof ActionGroup
            && ($action->getFlatActions()['frontendResourceDiagnostics'] ?? null) instanceof Action);

    assert($actionGroup instanceof ActionGroup);

    $frontendResourceDiagnosticsAction = $actionGroup->getFlatActions()['frontendResourceDiagnostics'];

    expect($actionGroup)->not->toBeNull()
        ->and(collect($actionGroup->getFlatActions())->keys()->all())->toContain('frontendResourceDiagnostics')
        ->and($frontendResourceDiagnosticsAction->getLabel())->toBe(__('capell-admin::generic.frontend_resource_diagnostics'))
        ->and($frontendResourceDiagnosticsAction->getTooltip())->toBe(__('capell-admin::generic.frontend_resource_diagnostics_description'))
        ->and($frontendResourceDiagnosticsAction->getModalDescription())->toBe(__('capell-admin::generic.frontend_resource_diagnostics_description'));
});

it('does not render content block conversion actions in the page header', function (): void {
    app()->bind('test-convert-content-to-blocks-header-extender', fn (): ResourceHeaderActionExtender => new class implements ResourceHeaderActionExtender
    {
        public function supports(string $pageClass): bool
        {
            return $pageClass === EditPage::class;
        }

        /** @return array<int, Action> */
        public function actions(): array
        {
            return [
                Action::make('convertContentToBlocks')
                    ->label(__('capell-admin::button.convert_to_content_blocks')),
            ];
        }
    });

    app()->tag(['test-convert-content-to-blocks-header-extender'], ResourceHeaderActionExtender::TAG);

    $page = Page::factory()->createOne();

    $livewire = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->assertSuccessful();

    $component = $livewire->instance();

    throw_unless($component instanceof EditPage, RuntimeException::class, 'Expected EditPage Livewire component instance.');

    $headerActionNames = collect($component->getCachedHeaderActions())
        ->reject(fn (object $action): bool => $action instanceof ActionGroup)
        ->map(fn (object $action): ?string => filamentObjectName($action))
        ->filter()
        ->values()
        ->all();

    expect($headerActionNames)->not->toContain('convertContentToBlocks');
});

it('removes the standalone view page header action', function (): void {
    $page = Page::factory()->withTranslations()->create();

    $livewire = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->assertSuccessful();

    $component = $livewire->instance();

    throw_unless($component instanceof EditPage, RuntimeException::class, 'Expected EditPage Livewire component instance.');

    // Req #3: the "View page" action group was removed; the live page is now
    // reachable via the clickable URL link in the subheading instead.
    $viewPageGroups = collect($component->getCachedHeaderActions())
        ->filter(fn (object $action): bool => $action instanceof ActionGroup && $action->getLabel() === __('capell-admin::button.view_page'));

    expect($viewPageGroups)->toHaveCount(0);
});

it('places the sitemap action inside the overflow menu rather than the top-level header', function (): void {
    // The real sitemap action is contributed by the site-discovery package's
    // ResourceHeaderActionExtender. That package is not loaded in the admin test
    // suite, so register an equivalent fake to exercise the EditPage partition logic.
    app()->bind('test-sitemap-header-extender', fn (): ResourceHeaderActionExtender => new class implements ResourceHeaderActionExtender
    {
        public function supports(string $pageClass): bool
        {
            return $pageClass === EditPage::class;
        }

        /** @return array<int, Action> */
        public function actions(): array
        {
            return [
                Action::make('sitemap')
                    ->label('Sitemap'),
            ];
        }
    });

    app()->tag(['test-sitemap-header-extender'], ResourceHeaderActionExtender::TAG);

    $page = Page::factory()->withTranslations()->create();

    $livewire = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->assertSuccessful();

    $component = $livewire->instance();

    throw_unless($component instanceof EditPage, RuntimeException::class, 'Expected EditPage Livewire component instance.');

    $headerActions = collect($component->getCachedHeaderActions());

    // The sitemap action must not appear as a top-level header button...
    $topLevelNames = $headerActions
        ->reject(fn (object $action): bool => $action instanceof ActionGroup)
        ->map(fn (object $action): ?string => $action->getName())
        ->all();

    expect($topLevelNames)->not->toContain('sitemap');

    // ...but it must still be present, nested within an overflow ActionGroup.
    $nestedNames = $headerActions
        ->filter(fn (object $action): bool => $action instanceof ActionGroup)
        ->flatMap(fn (ActionGroup $group): array => array_keys($group->getFlatActions()))
        ->all();

    expect($nestedNames)->toContain('sitemap');
});

it('hides the revisions header action when there are no revisions', function (): void {
    Route::get('/admin/pages/{record}/history', fn (): string => '')
        ->name('filament.admin.resources.pages.history');

    app()->instance('capell.workspace.page-draft-handler', new class
    {
        /** @param Pageable<Page> $record */
        public function countDrafts(Pageable $record): int
        {
            return 0;
        }
    });

    $page = Page::factory()->createOne();

    $livewire = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->assertSuccessful();

    $component = $livewire->instance();

    throw_unless($component instanceof EditPage, RuntimeException::class, 'Expected EditPage Livewire component instance.');

    expect(groupedHeaderAction($component, 'revisions')->isHiddenInGroup())->toBeTrue();
});

it('shows the revisions header action when a revision exists', function (): void {
    Route::get('/admin/pages/{record}/history', fn (): string => '')
        ->name('filament.admin.resources.pages.history');

    app()->instance('capell.workspace.page-draft-handler', new class
    {
        /** @param Pageable<Page> $record */
        public function countDrafts(Pageable $record): int
        {
            return 1;
        }
    });

    $page = Page::factory()->createOne();

    $livewire = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->assertSuccessful();

    $component = $livewire->instance();

    throw_unless($component instanceof EditPage, RuntimeException::class, 'Expected EditPage Livewire component instance.');

    expect(groupedHeaderAction($component, 'revisions')->isHiddenInGroup())->toBeFalse();
});

test('moves edit form actions above the form when configured', function (): void {
    $settings = AdminSettings::instance();
    $settings->form_action_position = AdminFormActionPositionEnum::AboveForm;
    $settings->save();

    $page = Page::factory()->createOne(['visible_until' => null]);

    $livewire = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])->assertSuccessful();

    $component = $livewire->instance();

    throw_unless($component instanceof EditPage, RuntimeException::class, 'Expected EditPage Livewire component instance.');

    $formActionNames = collect($component->getCachedFormActions())
        ->map(fn (object $action): ?string => filamentObjectName($action))
        ->filter()
        ->values()
        ->all();

    $headerActionNames = collect($component->getCachedHeaderActions())
        ->map(fn (object $action): string => $action instanceof ActionGroup ? 'actionGroup' : expectPresent($action->getName()))
        ->all();

    expect($formActionNames)->toBe([])
        ->and(array_slice($headerActionNames, 0, 2))->toBe(['save', 'cancel']);
});

it('can save', function (PageTypeEnum $pageTypeEnum): void {
    $site = Site::factory()->hasSiteDomains()->create();
    $languages = $site->siteDomains->map->language_id;

    $pageType = $pageTypeEnum->createPageType();

    $page = Page::factory()->site($site)->type($pageType)->create();
    setupPage($page, $languages);

    $newData = Page::factory()
        ->site($site)
        ->make();

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'name' => $newData->name,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh())
        ->name->toBe($newData->name)
        ->site_id->toBe($newData->site->getKey());
})->with(PageTypeEnum::cases());

it('can edit database-backed page fields from the admin form', function (): void {
    $site = Site::factory()->hasSiteDomains()->create();
    $languages = $site->siteDomains->map->language_id;

    $layout = $site->layouts()->save(Layout::factory()->make());
    $newLayout = $site->layouts()->save(Layout::factory()->make());
    $type = Blueprint::factory()->page()->create();
    $newType = Blueprint::factory()->page()->create();
    $parent = Page::factory()->site($site)->type($type)->layout($layout)->create();
    setupPage($parent, $languages);

    $page = Page::factory()
        ->site($site)
        ->type($type)
        ->layout($layout)
        ->parent($parent)
        ->create([
            'name' => 'Database Backed Page',
            'order' => 4,
            'meta' => [
                'show_hero' => true,
                'header_over_hero' => false,
                'hero_style' => 'default',
                'hero_height' => 'min(760px, 92vh)',
                'hero_asset_source' => 'element',
            ],
            'visible_from' => now()->subDay(),
            'visible_until' => now()->addMonth(),
        ]);
    setupPage($page, $languages);

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::form.hero_style_helper'))
        ->assertSee(__('capell-admin::form.hero_height_helper'))
        ->assertSee(__('capell-admin::form.hero_asset_source_helper'))
        ->assertSchemaStateSet(function (array $state) use ($layout, $parent, $site, $type): array {
            expect($state['name'])->toBe('Database Backed Page')
                ->and((int) $state['site_id'])->toBe($site->getKey())
                ->and((int) $state['parent_id'])->toBe($parent->getKey())
                ->and((int) $state['blueprint_id'])->toBe($type->getKey())
                ->and((int) $state['layout_id'])->toBe($layout->getKey())
                ->and($state['order'])->toBe(4.0)
                ->and($state['meta']['show_hero'])->toBeTrue()
                ->and($state['meta']['hero_style'])->toBe('default')
                ->and($state['meta']['hero_height'])->toBe('min(760px, 92vh)')
                ->and($state['meta']['hero_asset_source'])->toBe('element');

            return [];
        })
        ->fillForm([
            'name' => 'Database Backed Page Edited',
            'parent_id' => null,
            'blueprint_id' => $newType->getKey(),
            'layout_id' => $newLayout->getKey(),
            'order' => 9,
            'meta' => [
                'show_hero' => false,
                'header_over_hero' => true,
                'hero_style' => 'immersive',
                'hero_height' => '640px',
                'hero_asset_source' => 'page',
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $updatedPage = $page->refresh();

    expect($updatedPage)
        ->name->toBe('Database Backed Page Edited')
        ->parent_id->toBeNull()
        ->blueprint_id->toBe($newType->getKey())
        ->layout_id->toBe($newLayout->getKey())
        ->order->toBe(9)
        ->meta->toMatchArray([
            'show_hero' => false,
            'header_over_hero' => true,
            'hero_style' => 'immersive',
            'hero_height' => '640px',
            'hero_asset_source' => 'page',
        ]);

    // visible_from / visible_until are no longer edited via the main form — the
    // PublishStatusPanel owns them now — so they retain their created values.
    expect($updatedPage->visible_until)->toBeInstanceOf(DateTimeInterface::class);
});

it('can save with parent', function (): void {
    $site = Site::factory()->hasSiteDomains()->create();
    $languages = $site->siteDomains->map->language_id;

    $page = Page::factory()->site($site)->create();
    setupPage($page, $languages);

    $parent = Page::factory()->site($site)->create();
    setupPage($parent, $languages);

    $newData = Page::factory()
        ->parent($parent)
        ->site($site)
        ->make();

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'name' => $newData->name,
            'parent_id' => $newData->parent->getKey(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh())
        ->name->toBe($newData->name)
        ->parent_id->toBe($newData->parent->getKey())
        ->site_id->toBe($newData->site->getKey());
});

it('prevents assigning a parent page that is missing one of the edited page languages', function (): void {
    $english = Language::factory()->create(['code' => 'en', 'name' => 'English']);
    $french = Language::factory()->create(['code' => 'fr', 'name' => 'French']);
    $site = Site::factory()
        ->recycle($english)
        ->withTranslations([$english, $french])
        ->create();
    $type = Blueprint::factory()->page()->create();

    $parent = Page::factory()->site($site)->type($type)->create(['name' => 'Parent']);
    $parent->translations()->save(Translation::factory()->language($english)->make());

    $page = Page::factory()->site($site)->type($type)->create(['name' => 'Child']);
    $page->translations()->saveMany([
        Translation::factory()->language($english)->make(),
        Translation::factory()->language($french)->make(),
    ]);

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'parent_id' => $parent->getKey(),
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('capell-admin::message.page_language_parent', ['name' => 'French']));

    expect($page->refresh()->parent_id)->toBeNull();
});

it('save home page', function (): void {
    $site = Site::factory()->hasSiteDomains()->create();
    $languages = $site->siteDomains->map->language_id;

    $page = Page::factory()->site($site)->pending()->home()->create();
    setupPage($page, $languages);

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertFormFieldDoesNotExist('parent_id')
        ->call('save')
        ->assertHasNoFormErrors();

    // Saving a pending page is "Save and Publish": mutateFormDataBeforeSave clears
    // the draft sentinel so the page goes live. Scheduling a future date is now the
    // PublishStatusPanel's job, not the main form's.
    expect($page->refresh()->isPending())->toBeFalse();
});

it(
    'save page with custom configurator',
    /** @param class-string<ConfiguratorInterface> $configurator */
    function (string $configurator): void {
        $site = Site::factory()->hasSiteDomains()->create();
        $languages = $site->siteDomains->map->language_id;

        $page = Page::factory()
            ->site($site)
            ->for(Blueprint::factory()->page()->admin('configurator', $configurator::getKey()))
            ->create();
        setupPage($page, $languages);

        Livewire::test(EditPage::class, [
            'record' => $page->getRouteKey(),
        ])
            ->assertSuccessful()
            ->call('save')
            ->assertHasNoFormErrors();
    },
)->with([
    [DefaultPageConfigurator::class],
    [LandingPageConfigurator::class],
    [ResultsPageConfigurator::class],
]);

it('can delete', function (): void {
    $page = Page::factory()->createOne();

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->callAction(DeleteAction::class)
        ->assertHasNoFormErrors();

    assertSoftDeleted($page, ['id' => $page->id]);
});

it('shows the page permalink and compact slug edit control from slug input', function (): void {
    $language = Language::factory()->state(['locale' => 'en'])->create();
    $site = Site::factory()->language($language)->withTranslations()->create();
    $parent = Page::factory()->site($site)->withTranslations()->create();
    $page = Page::factory()->site($site)->parent($parent)->withTranslations()->create();

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertElementExists(
            '.page-title-with-slug-input',
            fn (AssertElement $elm): BaseAssert => $elm->find(
                'a.page-title-with-slug-link',
                fn (AssertElement $link): BaseAssert => $link->has('href', $page->pageUrl->full_url),
            )->find(
                'button.page-title-with-slug-edit-slug',
                fn (AssertElement $button): BaseAssert => $button->containsText(__('capell-admin::generic.permalink_action_edit')),
            ),
        );
});

it('constrains the edit page sidebar and translation tabs', function (): void {
    $language = Language::factory()->state(['locale' => 'en'])->create();
    $site = Site::factory()->language($language)->withTranslations()->create();
    $page = Page::factory()->site($site)->withTranslations()->create();

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSeeHtml('fixed-sidebar__wrapper')
        ->assertSeeHtml('fixed-sidebar__main');
});

it('saves translation SEO text', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->recycle($language)->withTranslations()->create();

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            sprintf('translations.record-%d.meta.title', $page->translation->id) => 'Custom SEO title',
            sprintf('translations.record-%d.meta.description', $page->translation->id) => 'Custom SEO description.',
            sprintf('translations.record-%d.meta.keywords', $page->translation->id) => 'capell, cms',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $translation = $page->translation->refresh();

    expect($translation->meta)
        ->toMatchArray([
            'title' => 'Custom SEO title',
            'description' => 'Custom SEO description.',
            'keywords' => 'capell, cms',
        ]);
});

it('escapes select changer labels', function (): void {
    $parent = Page::factory()
        ->withTranslations()
        ->create(['name' => 'Parent <script>alert(1)</script>']);

    $page = Page::factory()
        ->parent($parent)
        ->withTranslations()
        ->create(['name' => 'Page <script>alert(2)</script>']);

    $component = new ReflectionClass(EditPage::class)->newInstanceWithoutConstructor();
    $method = new ReflectionClass(EditPage::class)->getMethod('selectChangerItemLabel');

    $label = $method->invoke($component, $page->load('ancestors'))->toHtml();

    expect($label)
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<script>alert(2)</script>')
        ->toContain(e(Str::limit($parent->name, 30)))
        ->toContain('Page &lt;script&gt;alert(2)&lt;/script&gt;');
});

it('updates mounted layout state and removes aggregate count attributes before saving', function (): void {
    $layout = Layout::factory()->createOne();
    $newLayout = Layout::factory()->createOne();
    $page = Page::factory()
        ->layout($layout)
        ->withTranslations()
        ->children()
        ->createOne();

    $component = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->instance();

    throw_unless($component instanceof EditPage, RuntimeException::class, 'Expected EditPage Livewire component instance.');

    $component->layoutUpdated($newLayout->getKey());

    $recordWithCounts = $page->fresh()->loadCount('children');

    expect(data_get($component->data, 'layout_id'))->toBe($newLayout->getKey())
        ->and($component->record->layout_id)->toBe($newLayout->getKey())
        ->and($component->record->relationLoaded('layout'))->toBeTrue()
        ->and($recordWithCounts->getAttributes())->toHaveKey('children_count');

    $component->stripCountAttributes($recordWithCounts);

    expect($recordWithCounts->getAttributes())->not->toHaveKey('children_count');
});

it('accepts unchanged parent language state and reports invalid draft handlers clearly', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    $parent = Page::factory()
        ->site($site)
        ->withTranslations()
        ->createOne();
    $page = Page::factory()
        ->site($site)
        ->parent($parent)
        ->withTranslations()
        ->createOne();

    $component = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->instance();

    throw_unless($component instanceof EditPage, RuntimeException::class, 'Expected EditPage Livewire component instance.');

    $component->data['parent_id'] = $parent->getKey();

    $validateParentLanguages = new ReflectionMethod(EditPage::class, 'validateParentLanguages');

    expect($validateParentLanguages->invoke($component))->toBeTrue();

    app()->instance('capell.workspace.page-draft-handler', new class {});

    expect(function () use ($component): void {
        $component->saveAsDraft();
    })
        ->toThrow(InvalidArgumentException::class, 'Page draft handler method saveAsDraft is not callable.');
});

it('requires confirmation before adding redirects from the url change notification', function (): void {
    $page = Page::factory()->withTranslations()->createOne();

    $component = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->instance();

    throw_unless($component instanceof EditPage, RuntimeException::class, 'Expected EditPage Livewire component instance.');

    $actionFactory = new ReflectionMethod(EditPage::class, 'addUrlRedirectNotificationAction');
    $action = $actionFactory->invoke($component);

    throw_unless($action instanceof Action, RuntimeException::class, 'Expected add redirect notification action.');

    expect($action->isConfirmationRequired())->toBeTrue()
        ->and($action->getModalDescription())->toBe(__('capell-admin::message.add_url_redirect_confirmation'));
});

it('shows warning when url changes', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->recycle($language)->withTranslations(slug: 'old-slug')->create();
    $originalUrl = $page->pageUrl()->where('language_id', $language->getKey())->value('url');

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            sprintf('translations.record-%d.meta.slug', $page->translation->id) => 'new-slug',
        ])
        ->assertSchemaStateSet([
            sprintf('translations.record-%d.meta.slug', $page->translation->id) => 'new-slug',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('capell-admin::message.url_changed'));

    assertDatabaseHas(PageUrl::class, [
        'pageable_id' => $page->getKey(),
        'pageable_type' => $page->getMorphClass(),
        'language_id' => $language->getKey(),
        'url' => '/new-slug',
        'type' => null,
    ]);

    assertDatabaseHas(PageUrl::class, [
        'pageable_id' => $page->getKey(),
        'pageable_type' => $page->getMorphClass(),
        'language_id' => $language->getKey(),
        'url' => $originalUrl,
        'type' => 'redirect',
        'is_manual' => false,
    ]);

    assertDatabaseCount(PageUrl::class, 2);
});

it('does not show warning when url does not change', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->recycle($language)->withTranslations(slug: 'old-slug')->create();

    $originalUrl = $page->pageUrl()->where('language_id', $language->getKey())->value('url');

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotNotified(__('capell-admin::message.url_changed'))
        ->dispatch('add-url-redirects', [$language->id => $originalUrl])
        ->assertNotNotified(__('capell-admin::message.url_redirects_added'));

    assertDatabaseCount(PageUrl::class, 1);
});

it('adds url redirect when url changes and user confirms', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->recycle($language)->withTranslations(slug: 'old-slug')->create();

    $originalUrl = $page->pageUrl()->where('language_id', $language->getKey())->value('url');

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->fillForm([
            sprintf('translations.record-%d.meta.slug', $page->translation->id) => 'new-slug',
        ])
        ->assertSchemaStateSet([
            sprintf('translations.record-%d.meta.slug', $page->translation->id) => 'new-slug',
        ])
        ->assertSuccessful()
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('capell-admin::message.url_changed'))
        ->dispatch('add-url-redirects', [$language->id => $originalUrl])
        ->assertNotified(__('capell-admin::message.url_redirects_added'));

    assertDatabaseHas(PageUrl::class, [
        'pageable_id' => $page->getKey(),
        'pageable_type' => $page->getMorphClass(),
        'language_id' => $language->getKey(),
        'url' => '/new-slug',
        'type' => null,
    ]);

    assertDatabaseHas(PageUrl::class, [
        'pageable_id' => $page->getKey(),
        'pageable_type' => $page->getMorphClass(),
        'language_id' => $language->getKey(),
        'url' => $originalUrl,
        'type' => 'redirect',
    ]);

    assertDatabaseCount(PageUrl::class, 2);
});

it('requires page url create permission before adding url redirects from page edits', function (): void {
    foreach (['View:Page', 'Update:Page', 'Create:PageUrl'] as $permission) {
        Permission::findOrCreate($permission);
    }

    $language = Language::factory()->createOne();
    $page = Page::factory()->recycle($language)->withTranslations(slug: 'old-slug')->create();
    $user = test()->createUserWithPermission(['View:Page', 'Update:Page']);
    $user->assignedSiteIds = collect([$page->site_id]);

    test()->actingAs($user);

    $originalUrl = $page->pageUrl()->where('language_id', $language->getKey())->value('url');

    $component = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->fillForm([
            sprintf('translations.record-%d.meta.slug', $page->translation->id) => 'new-slug',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('capell-admin::message.url_changed'));

    expect($user->can('create', PageUrl::class))->toBeFalse();

    $component
        ->dispatch('add-url-redirects', [$language->id => $originalUrl])
        ->assertForbidden();

    assertDatabaseCount(PageUrl::class, 2);
});

it('ignores tampered redirect event payloads that were not recorded url changes', function (): void {
    $language = Language::factory()->createOne();
    $otherLanguage = Language::factory()->createOne();
    $page = Page::factory()->recycle($language)->withTranslations(slug: 'old-slug')->create();

    $originalUrl = $page->pageUrl()->where('language_id', $language->getKey())->value('url');

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->fillForm([
            sprintf('translations.record-%d.meta.slug', $page->translation->id) => 'new-slug',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->dispatch('add-url-redirects', [
            $language->id => $originalUrl,
            $otherLanguage->id => '/not-a-recorded-change',
        ])
        ->assertNotified(__('capell-admin::message.url_redirects_added'));

    assertDatabaseHas(PageUrl::class, [
        'language_id' => $language->getKey(),
        'url' => $originalUrl,
        'type' => 'redirect',
    ]);

    expect(PageUrl::query()->where('language_id', $otherLanguage->getKey())->exists())->toBeFalse();
});

test('can save content string into blocks', function (): void {
    $language = Language::factory()->createOne();
    $type = Blueprint::factory()->page()->contentStructure(ContentStructure::Blocks)->create();
    $page = Page::factory()->recycle($language)->type($type)->withTranslations()->create();
    $content = $page->translation->content;

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->call('save')
        ->assertHasNoFormErrors();

    $storedContent = $page->translation->refresh()->content;
    $savedContent = is_string($storedContent)
        ? json_decode($storedContent, true, flags: JSON_THROW_ON_ERROR)
        : $storedContent;

    expect($savedContent)->toBeArray()
        ->and($savedContent[0]['type'])->toBe('content')
        ->and($savedContent[0]['data']['content'])->toBe($content)
        ->and($savedContent[0]['data']['mediaAlign'])->toBeNull()
        ->and($savedContent[0]['data']['mediaOrdering'])->toBeNull()
        ->and(Str::isUuid($savedContent[0]['data']['__capell']['instance_id']))->toBeTrue();
});

test('can save content blocks into string', function (): void {
    $language = Language::factory()->createOne();
    $type = Blueprint::factory()->page()->create();
    $page = Page::factory()->recycle($language)->type($type)->create();

    $translation = Translation::factory()
        ->language($language)
        ->translatable($page)
        ->content(ContentStructure::Blocks)
        ->create();

    $content = ExtractContentFromBlocksAction::run(json_decode((string) $translation->content, true));

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Translation::class, [
        'translatable_id' => $page->getKey(),
        'translatable_type' => $page->getMorphClass(),
        'language_id' => $language->getKey(),
        'content' => $content,
    ]);
});

test('can convert html content to blocks from the content editor hint action', function (): void {
    $language = Language::factory()->createOne();
    $type = Blueprint::factory()->page()->contentStructure(ContentStructure::Html)->create();
    $page = Page::factory()->recycle($language)->type($type)->withTranslations()->create();
    $translation = $page->translation;
    $content = $translation->content;

    $component = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->callFormComponentAction(
            sprintf('translations.record-%d.content', $translation->getKey()),
            'convertContentToBlocks',
        )
        ->assertNotified(__('capell-admin::message.content_structure_updated'));

    expect($page->refresh()->content_structure)->toBe(ContentStructure::Blocks);

    expect(data_get($component->instance()->data, sprintf('translations.record-%d.content', $translation->getKey())))
        ->toBe([['type' => 'content', 'data' => [
            'content' => $content,
        ]]]);
});

test('content structure conversion action is only added to the main page content editor', function (): void {
    $contentEditor = ContentEditor::make('content');
    $heroEditor = ContentEditor::make('hero');

    expect(collect($contentEditor->getHintActions())->map->getName()->all())
        ->toContain('convertContentToBlocks')
        ->and(collect($heroEditor->getHintActions())->map->getName()->all())
        ->not->toContain('convertContentToBlocks', 'convertContentToHtml');
});

test('default content block uses a simplified content-only schema', function (): void {
    $schema = Schema::make(Capell\Admin\Tests\Fixtures\Livewire::make())
        ->statePath('data')
        ->components([
            ContentBuilder::make('content'),
        ]);

    $builder = $schema->getComponents()[0];

    throw_unless($builder instanceof ContentBuilder, RuntimeException::class, 'Expected content builder.');

    $contentBlock = collect($builder->getBlocks())
        ->first(fn (object $block): bool => method_exists($block, 'getName') && $block->getName() === 'content');

    throw_unless($contentBlock instanceof Block, RuntimeException::class, 'Expected content block.');

    $childSchema = $contentBlock->getChildSchema();

    throw_unless($childSchema instanceof Schema, RuntimeException::class, 'Expected child schema.');

    $componentNames = collect($childSchema->getComponents())
        ->map(fn (object $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
        ->filter()
        ->values()
        ->all();

    expect($componentNames)
        ->toContain('content')
        ->not->toContain('media', 'mediaAlign', 'mediaOrdering', '__capell');
});

test('can convert content blocks back to html from the content editor hint action', function (): void {
    $language = Language::factory()->createOne();
    $type = Blueprint::factory()->page()->contentStructure(ContentStructure::Blocks)->create();
    $page = Page::factory()->recycle($language)->type($type)->create();

    $translation = Translation::factory()
        ->language($language)
        ->translatable($page)
        ->content(ContentStructure::Blocks)
        ->create();

    $content = ExtractContentFromBlocksAction::run(
        is_array($translation->content)
            ? $translation->content
            : (array) json_decode((string) $translation->content, true),
    );

    $component = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->callFormComponentAction(
            sprintf('translations.record-%d.content', $translation->getKey()),
            'convertContentToHtml',
        )
        ->assertNotified(__('capell-admin::message.content_structure_updated'));

    expect($page->refresh()->content_structure)->toBe(ContentStructure::Html);

    $editorContent = data_get($component->get('data'), sprintf('translations.record-%d.content', $translation->getKey()));

    expect($editorContent)->toBeArray()
        ->and(RichContentRenderer::make($editorContent)->toHtml())->toBe($content);
});

test('can edit page type and handle updated the content data changing to builder', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->recycle($language)->withTranslations()->create();
    $content = $page->translation->content;

    $livewire = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ]);

    $livewire->assertSuccessful()
        ->call('save')
        ->assertHasNoFormErrors();

    $contentBuilder = $page->refresh();

    assertDatabaseHas(Translation::class, [
        'id' => $contentBuilder->translations->first()->getKey(),
        'language_id' => $language->getKey(),
        'content' => $content,
    ]);

    $livewire->mountAction(TestAction::make('editOption')->schemaComponent('blueprint_id', schema: 'form'))
        ->set('mountedActions.0.data.meta.content_structure', ContentStructure::Blocks->value)
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($page->blueprint->refresh())
        ->meta->content_structure->toBe(ContentStructure::Blocks->value);

    $livewire->call('save')
        ->assertHasNoFormErrors();

    $savedContent = Translation::query()
        ->findOrFail($contentBuilder->translations->first()->getKey())
        ->content;

    expect($savedContent)->toBeArray()
        ->and($savedContent[0]['type'])->toBe('content')
        ->and($savedContent[0]['data']['content'])->toBe($content)
        ->and($savedContent[0]['data']['mediaAlign'])->toBeNull()
        ->and($savedContent[0]['data']['mediaOrdering'])->toBeNull()
        ->and(Str::isUuid($savedContent[0]['data']['__capell']['instance_id']))->toBeTrue();
});

it('warns editors when the page type content structure changes', function (): void {
    $type = Blueprint::factory()->page()->contentStructure(ContentStructure::Html)->create();
    $page = Page::factory()->type($type)->create();

    $component = Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->call('pageTypeContentStructureUpdated', ContentStructure::Blocks)
        ->assertNotified(__('capell-admin::message.content_structure_updated'));

    expect($component->instance()->record->refresh()->content_structure)
        ->toBe(ContentStructure::Blocks);
});
