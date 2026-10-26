<?php

declare(strict_types=1);

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Filament\Actions\Page\CreatePageAction;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\LandingPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\ResultsPageConfigurator;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Admin\Tests\Support\SentNotifications;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Creator\LayoutCreator;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__, 8) . '/tests/Support/DomQuery.php';

uses(CreatesAdminUser::class)
    ->group('page');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('labels the primary create action as save and publish', function (): void {
    $component = new CreatePage;
    $method = new ReflectionMethod($component, 'getCreateFormAction');

    /** @var Action $createAction */
    $createAction = $method->invoke($component);
    expect($createAction)->toBeInstanceOf(Action::class)
        ->and(filamentText($createAction->getLabel()))->toBe(__('capell-admin::button.save_and_publish'));
});

it('renders guided setup and publish sections on the create page form', function (): void {
    Language::factory()->createOne();
    Site::factory()->withTranslations()->createOne();
    Blueprint::factory()->page()->default()->createOne();

    $form = Livewire::test(CreatePage::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::form.page_setup'))
        ->assertSee(__('capell-admin::generic.page_setup_description'))
        ->assertSee(__('capell-admin::generic.page_site_info'))
        ->assertSee(__('capell-admin::generic.parent_page_info'))
        ->assertSee(__('capell-admin::form.publish_setup'))
        ->assertSee(__('capell-admin::generic.publish_setup_description'))
        ->assertSee(__('capell-admin::generic.page_layout_select_info'))
        ->assertSee('Save as draft')
        ->assertSee('Save and Publish');

    $label = domElement(domXPath($form->html()), '//label[@for="form.visible_from"]');
    expect(trim(domText($label)))->toBe('Visible from');

    expect(strip_tags($form->html()))->toContain('To schedule a future date, save as draft first, then use Schedule.')
        ->not->toContain('leave it empty and use Publish now');
});

it('can search parent results', function (): void {
    $parent = Page::factory()->withTranslations()->create();

    $livewire = Livewire::test(CreatePage::class);
    $instance = $livewire->instance();
    assert($instance instanceof CreatePage);
    $schemaName = expectPresent($instance->getDefaultTestingSchemaName());
    $configurator = expectPresent($instance->getSchema($schemaName));
    $component = expectPresent($configurator->getComponent('parent_id'));

    $livewire->call('callSchemaComponentMethod', filamentObjectKey($component), $parent->name)
        ->assertSuccessful();
});

test(
    'can create new page',
    /** @param class-string<ConfiguratorInterface> $configurator */
    function (string $configurator): void {
        $language = Language::factory()->createOne();
        $site = Site::factory()->recycle($language)->withTranslations()->create();
        $type = Blueprint::factory()->page()->admin('configurator', $configurator::getKey())->create();

        $newData = Page::factory()->make();

        Livewire::test(CreatePage::class)
            ->assertSuccessful()
            ->set('data.translations', [])
            ->fillForm([
                'name' => $newData->name,
                'blueprint_id' => $type->id,
                'translations' => [
                    (string) Str::uuid() => [
                        'language_id' => $language->id,
                        'title' => $newData->name,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        assertDatabaseHas(Page::class, [
            'name' => $newData->name,
            'site_id' => $site->id,
        ]);

        assertDatabaseHas(Translation::class, [
            'title' => $newData->name,
            'language_id' => $language->id,
        ]);
    },
)->with([
    [DefaultPageConfigurator::class],
    [LandingPageConfigurator::class],
    [ResultsPageConfigurator::class],
]);

// Regression guard for CreatePage::hasDatabaseTransactions(): Filament opens its
// transaction in CreateRecord::create() and calls afterCreate() inside it, so the whole
// SavePageAuthoringAction/PageSaved listener chain is atomic with the pages row. Nothing
// else asserts that, so dropping the override would otherwise leave the suite green.
it('rolls back the page when the filament create page fails saving authoring data', function (): void {
    $language = Language::factory()->createOne();
    Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->admin('configurator', DefaultPageConfigurator::getKey())->create();

    $newData = Page::factory()->make();

    Event::listen('eloquent.creating: ' . Translation::class, function (): void {
        throw new RuntimeException('Translation creation failed.');
    });

    expect(function () use ($language, $type, $newData): void {
        Livewire::test(CreatePage::class)
            ->assertSuccessful()
            ->set('data.translations', [])
            ->fillForm([
                'name' => $newData->name,
                'blueprint_id' => $type->id,
                'translations' => [
                    (string) Str::uuid() => [
                        'language_id' => $language->id,
                        'title' => $newData->name,
                    ],
                ],
            ])
            ->call('create');
    })->toThrow(RuntimeException::class, 'Translation creation failed.');

    assertDatabaseMissing(Page::class, ['name' => $newData->name]);
});

it('prevents creating a page if parent missing languages', function (): void {
    $languages = Language::factory()->count(2)->create();
    $language1 = $languages->first();
    $language2 = $languages->get(1);
    $site = Site::factory()->language($language1)->withTranslations($languages)->create();

    $parent = Page::factory()->site($site)->withTranslations($language1)->create();

    $newData = Page::factory()->site($site)->make();

    assertDatabaseCount(Language::class, 2);

    Livewire::test(CreatePage::class)
        ->assertSuccessful()
        ->set('data.translations', [])
        ->fillForm([
            'name' => $newData->name,
            'site_id' => $site->id,
            'parent_id' => $parent->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['parent_id'])
        ->assertSee(__('capell-admin::parent_languages.missing', ['languages' => $language2->name]));

    assertDatabaseMissing(Page::class, [
        'name' => $newData->name,
        'parent_id' => $parent->id,
    ]);
});

describe('from edit page', function (): void {
    test(
        'can create new page',
        /** @param class-string<ConfiguratorInterface> $configurator */
        function (string $configurator): void {
            $page = Page::factory()->withTranslations()->create();
            $type = Blueprint::factory()->page()->admin('configurator', $configurator::getKey())->create();
            $language = $page->site->language;

            $newData = Page::factory()->make();
            $slug = str($newData->name)->slug()->toString();

            Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
                ->assertSuccessful()
                ->mountAction(TestAction::make(CreatePageAction::class))
                ->set('mountedActions.0.data.translations', [])
                ->fillForm([
                    'blueprint_id' => $type->id,
                    'name' => $newData->name,
                    'parent_id' => null,
                    'translations' => [
                        (string) Str::uuid() => [
                            'language_id' => $language->id,
                            'title' => $newData->name,
                            'meta' => [
                                'slug' => $slug,
                            ],
                        ],
                    ],
                ])
                ->callMountedAction()
                ->assertHasNoFormErrors();

            // Original not changed?
            assertDatabaseHas(Page::class, [
                'name' => $page->name,
            ]);

            assertDatabaseHas(Translation::class, [
                'translatable_id' => $page->getKey(),
                'translatable_type' => $page->getMorphClass(),
                'title' => $page->translations->firstWhere('language_id', $language->id)->title,
                'language_id' => $language->id,
            ]);

            // New
            assertDatabaseHas(Page::class, [
                'name' => $newData->name,
            ]);

            assertDatabaseHas(Translation::class, [
                'title' => $newData->name,
                'language_id' => $language->id,
            ]);

            // Ensure the original is not modified
            assertDatabaseHas(Translation::class, [
                'title' => $page->translations->first()->title,
            ]);

            assertDatabaseHas(PageUrl::class, [
                'url' => '/' . $slug,
            ]);
        },
    )->with([
        [DefaultPageConfigurator::class],
        [LandingPageConfigurator::class],
        [ResultsPageConfigurator::class],
    ]);

    // Regression guard for CreatePageAction::setUp()'s ->databaseTransaction().
    it('rolls back the page when the modal create action fails saving authoring data', function (): void {
        $page = Page::factory()->withTranslations()->create();
        $type = Blueprint::factory()->page()->admin('configurator', DefaultPageConfigurator::getKey())->create();
        $language = $page->site->language;

        $newData = Page::factory()->make();
        $slug = str($newData->name)->slug()->toString();

        Event::listen('eloquent.creating: ' . Translation::class, function (): void {
            throw new RuntimeException('Translation creation failed.');
        });

        expect(function () use ($page, $type, $language, $newData, $slug): void {
            Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
                ->assertSuccessful()
                ->mountAction(TestAction::make(CreatePageAction::class))
                ->set('mountedActions.0.data.translations', [])
                ->fillForm([
                    'blueprint_id' => $type->id,
                    'name' => $newData->name,
                    'parent_id' => null,
                    'translations' => [
                        (string) Str::uuid() => [
                            'language_id' => $language->id,
                            'title' => $newData->name,
                            'meta' => ['slug' => $slug],
                        ],
                    ],
                ])
                ->callMountedAction();
        })->toThrow(RuntimeException::class, 'Translation creation failed.');

        assertDatabaseMissing(Page::class, ['name' => $newData->name]);
    });

    it('required fields are required', function (): void {
        $page = Page::factory()->createOne();

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->assertSuccessful()
            ->callAction(CreatePageAction::class, [
                'name' => '',
                'layout_id' => '',
            ])
            ->assertHasFormErrors([
                'name' => 'required',
                'layout_id' => 'required',
            ]);
    });
});

describe('from list page', function (): void {
    it('can create page', function (PageTypeEnum $typeEum): void {
        Blueprint::factory()->page()->default()->create();
        $type = $typeEum->createPageType();

        $language = Language::factory()->createOne();
        $site = Site::factory()->recycle($language)->hasSiteDomains()->create();

        $layoutEnum = $typeEum->defaultLayoutEnum();
        $layout = resolve(LayoutCreator::class)->create($layoutEnum);

        $newData = Page::factory()->make();

        Livewire::test(ListPages::class)
            ->assertSuccessful()
            ->mountAction('create')
            ->set('mountedActions.0.data.translations', [])
            ->fillForm([
                'site_id' => $site->id,
                'blueprint_id' => $type->id,
                'name' => $newData->name,
                'layout_id' => $layout->id,
            ])
            // ->callMountedAction()
            ->set('mountedActions.0.data.hide_system_pages', false)
            ->set(
                'mountedActions.0.data.translations',
                $site->languages->mapWithKeys(fn (Language $language): array => [
                    (string) Str::uuid() => [
                        'language_id' => $language->getKey(),
                        'title' => $newData->name,
                        'meta' => [
                            'slug' => str($newData->name)->slug()->toString(),
                        ],
                    ],
                ])
                    ->toArray(),
            )
            ->assertSchemaStateSet([
                'name' => $newData->name,
                'blueprint_id' => $type->id,
                'layout_id' => $layout->id,
                'system_pages' => false,
                'site_id' => $site->id,
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        assertDatabaseHas(Page::class, [
            'name' => $newData->name,
            'blueprint_id' => $type->id,
            'layout_id' => $layout->id,
            'site_id' => $site->id,
        ]);
    })
        ->with(PageTypeEnum::cases());

    test(
        'can create configurator page',
        /** @param class-string<ConfiguratorInterface> $configurator */
        function (string $configurator): void {
            $type = Blueprint::factory()->page()->admin('configurator', $configurator::getKey())->create();

            $language = Language::factory()->createOne();
            $site = Site::factory()->recycle($language)->hasSiteDomains()->create();

            $newData = Page::factory()->make();

            Livewire::test(ListPages::class)
                ->assertSuccessful()
                ->mountAction('create')
                ->set('mountedActions.0.data.translations', [])
                ->fillForm([
                    'site_id' => $site->id,
                    'blueprint_id' => $type->id,
                    'name' => $newData->name,
                ])
                // ->callMountedAction()
                ->set('mountedActions.0.data.hide_system_pages', false)
                ->set(
                    'mountedActions.0.data.translations',
                    $site->languages->mapWithKeys(fn (Language $language): array => [
                        (string) Str::uuid() => [
                            'language_id' => $language->getKey(),
                            'title' => $newData->name,
                            'meta' => [
                                'slug' => str($newData->name)->slug()->toString(),
                            ],
                        ],
                    ])
                        ->toArray(),
                )
                ->assertSchemaStateSet([
                    'name' => $newData->name,
                    'blueprint_id' => $type->id,
                    'system_pages' => false,
                    'site_id' => $site->id,
                ])
                ->callMountedAction()
                ->assertHasNoFormErrors();

            assertDatabaseHas(Page::class, [
                'name' => $newData->name,
                'blueprint_id' => $type->id,
                'site_id' => $site->id,
            ]);
        },
    )
        ->with([
            [DefaultPageConfigurator::class],
            [LandingPageConfigurator::class],
            [ResultsPageConfigurator::class],
        ]);

    it('required fields are required', function (): void {
        Language::factory()->createOne();
        Blueprint::factory()->page()->default()->create();

        Livewire::test(ListPages::class)
            ->assertSuccessful()
            ->callAction('create', [
                'name' => '',
                'layout_id' => '',
                'site_id' => '',
            ]);

        expect(Page::query()->count())->toBe(0);
    });
});

it('keeps the selected site when opening quick create', function (): void {
    Site::factory()->withTranslations()->create(['default' => true]);
    $selected = Site::factory()->withTranslations()->create(['default' => false]);
    Blueprint::factory()->page()->default()->createOne();

    session()->put('capell.current_site_id', $selected->id);

    Livewire::test(ListPages::class)
        ->mountAction('create')
        ->assertSchemaStateSet(['site_id' => $selected->id]);
});

it('keeps the site tab in page type chooser links', function (): void {
    Site::factory()->withTranslations()->create(['default' => true]);
    $selected = Site::factory()->withTranslations()->create(['default' => false]);
    Blueprint::factory()->page()->default()->createOne();

    Livewire::test(ListPages::class)
        ->set('activeTab', (string) $selected->id)
        ->mountAction('choosePageType')
        ->assertMountedActionModalSeeHtml('site_id=' . $selected->id);
});

it('validates duplicate URLs when creating from the conflicting page editor', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $translation = $page->translations()->firstOrFail();
    $slug = 'duplicate-page';
    $translation->update(['meta' => ['slug' => $slug]]);
    $uuid = (string) Str::uuid();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->mountAction(TestAction::make(CreatePageAction::class))
        ->set('mountedActions.0.data.translations', [])
        ->fillForm([
            'name' => 'Duplicate page',
            'site_id' => $page->site_id,
            'blueprint_id' => $page->blueprint_id,
            'layout_id' => $page->layout_id,
            'parent_id' => $page->parent_id,
            'translations' => [
                $uuid => [
                    'language_id' => $translation->language_id,
                    'title' => 'Duplicate page',
                    'meta' => ['slug' => $slug],
                ],
            ],
        ])
        ->callMountedAction()
        ->assertHasFormErrors(['translations.' . $uuid . '.meta.slug' => 'unique']);

    assertDatabaseMissing(Page::class, ['name' => 'Duplicate page']);
});

it('keeps the edited page site when opening quick create', function (): void {
    Site::factory()->withTranslations()->create(['default' => true]);
    $selected = Site::factory()->withTranslations()->create(['default' => false]);
    Blueprint::factory()->page()->default()->createOne();
    $page = Page::factory()->withTranslations()->createOne(['site_id' => $selected->id]);

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->mountAction(TestAction::make(CreatePageAction::class))
        ->assertSchemaStateSet(['site_id' => $selected->id]);
});

it('keeps the selected site when opening the full page form', function (): void {
    Site::factory()->withTranslations()->create(['default' => true]);
    $selected = Site::factory()->withTranslations()->create(['default' => false]);
    Blueprint::factory()->page()->default()->createOne();
    session()->put('capell.current_site_id', $selected->id);

    Livewire::test(CreatePage::class)
        ->assertSchemaStateSet(['site_id' => $selected->id]);
});

it('explains missing parent languages before saving without clearing child input', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $parent = Page::factory()->site($site)->withTranslations($english)->createOne();
    Blueprint::factory()->page()->default()->createOne();
    $component = Livewire::test(CreatePage::class)->fillForm([
        'site_id' => $site->id,
        'parent_id' => $parent->id,
        'name' => 'Keep my child draft',
        'translations' => [
            'english' => ['language_id' => $english->id, 'title' => 'English draft'],
            'french' => ['language_id' => $french->id, 'title' => 'French draft'],
        ],
    ]);

    $component->assertSee(__('capell-admin::parent_languages.missing', ['languages' => $french->name]))
        ->assertFormSet(['name' => 'Keep my child draft']);

    $parent->translations()->create(['language_id' => $french->id, 'title' => 'French parent']);
    $component->callAction(TestAction::make('recheck-parent-languages')->schemaComponent('parent_id', schema: 'form'));
    $component->assertSee(__('capell-admin::parent_languages.compatible'))
        ->assertFormSet(['name' => 'Keep my child draft']);
});

it('opens the requested page translation rather than the default language', function (bool $creating): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $page = Page::factory()->site($site)->withTranslations([$english, $french])->createOne();

    Livewire::withQueryParams(['language_id' => $french->id, 'site_id' => $site->id])
        ->test($creating ? CreatePage::class : EditPage::class, $creating ? [] : ['record' => $page->getRouteKey()])
        ->assertFormFieldExists('translations', function ($field) use ($french): bool {
            $rows = array_values($field->getState());

            return (int) ($rows[$field->getActiveTab() - 1]['language_id'] ?? 0) === $french->id;
        });
})->with(['edit' => false, 'create' => true]);

it('carries the filtered language into edit type chooser and quick create', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $type = Blueprint::factory()->page()->default()->createOne();
    $page = Page::factory()->site($site)->type($type)->withTranslations([$english, $french])->createOne();

    Livewire::test(ListPages::class)
        ->set('tableFilters.filter.language_id', $french->id)
        ->assertTableActionHasUrl('edit', GetEditPageResourceUrlAction::run($page) . '?language_id=' . $french->id, $page)
        ->mountAction('choosePageType')
        ->assertMountedActionModalSeeHtml('language_id=' . $french->id);

    $quick = Livewire::test(ListPages::class)
        ->set('tableFilters.filter.language_id', $french->id)
        ->mountAction('create');
    $rows = $quick->get('mountedActions.0.data.translations');
    expect(array_map(intval(...), array_column($rows, 'language_id')))->toBe([$french->id]);
});

it('rechecks parent languages even when editing an unchanged parent relationship', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $parent = Page::factory()->site($site)->withTranslations($english)->createOne();
    $child = Page::factory()->site($site)->parent($parent)->withTranslations($english)->createOne();
    $component = Livewire::test(EditPage::class, ['record' => $child->getRouteKey()]);
    $translations = $component->get('data.translations');
    $translations['new-french'] = ['language_id' => $french->id, 'title' => 'French child', 'meta' => ['slug' => 'french-child']];

    $component->set('data.translations', $translations)
        ->call('save')
        ->assertNotified(__('capell-admin::message.page_language_parent', ['name' => $french->name]));

    expect($child->translations()->where('language_id', $french->id)->exists())->toBeFalse();
});

it('updates draft preview language from the active saved tab and disables unsaved translations', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $page = Page::factory()->pending()->site($site)->withTranslations([$english, $french])->createOne();
    $component = Livewire::withQueryParams(['language_id' => $french->id])->test(EditPage::class, ['record' => $page->getRouteKey()]);
    $component->assertActionEnabled('previewDraftPage');

    $action = $component->instance()->getAction('previewDraftPage');
    expect($action->getUrl())->toContain('language_id=' . $french->id);

    $page->translations()->where('language_id', $french->id)->delete();
    $component->set('data.activeTab', 2)->assertActionDisabled('previewDraftPage');
});

it('keeps the final parent language warning honest when the editor cannot update the parent', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $parent = Page::factory()->site($site)->withTranslations($english)->createOne();
    $child = Page::factory()->site($site)->parent($parent)->withTranslations([$english, $french])->createOne();
    foreach (['View:Page', 'Update:Page'] as $permission) {
        Permission::findOrCreate($permission);
    }

    $actor = test()->createUserWithPermission(['View:Page', 'Update:Page']);
    $actor->assignedSiteIds = collect([$site->id]);

    test()->actingAs($actor);
    Gate::before(fn (mixed $user, string $ability, array $arguments): ?bool => $ability === 'update' && ($arguments[0] ?? null) instanceof Page && $arguments[0]->is($parent) ? false : null);

    Livewire::test(EditPage::class, ['record' => $child->getRouteKey()])->call('save');
    SentNotifications::component()
        ->assertSee(__('capell-admin::parent_languages.ask_editor'))
        ->assertDontSee(GetEditPageResourceUrlAction::run($parent));
});

it('shares site language membership across repeated row and edit URL callbacks', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $type = Blueprint::factory()->page()->default()->createOne();
    $pages = Page::factory()->count(3)->site($site)->type($type)->withTranslations([$english, $french])->create();
    test()->actingAs(ScopedAdminUser::make(collect([$site->id])));
    $component = Livewire::test(ListPages::class)->set('tableFilters.filter.language_id', $french->id);
    $table = $component->instance()->getTable();
    $edit = $table->getAction('edit');
    assert($edit instanceof Action);
    $previousRequest = request();
    try {
        app()->instance('request', Request::create('/admin'));
        // Warm the unrelated resource URL resolver before measuring membership reads, under the same request root as the callbacks.
        $urls = $pages->mapWithKeys(fn (Page $page): array => [$page->id => GetEditPageResourceUrlAction::run($page) . '?language_id=' . $french->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($pages as $page) {
            $edit->record($page);
            for ($evaluation = 0; $evaluation < 5; $evaluation++) {
                expect($table->getRecordUrl($page))->toBe($urls[$page->id])
                    ->and($edit->getUrl())->toBe($urls[$page->id]);
            }
        }

        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from [`"\[]?(sites|site_domains|languages)[`"\]]?(\s|$)/', $query['query']) === 1);
        DB::disableQueryLog();
        expect($queries)->toHaveCount(4);
    } finally {
        DB::disableQueryLog();
        app()->instance('request', $previousRequest);
    }
});
