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
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

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

    Livewire::test(CreatePage::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::form.page_setup'))
        ->assertSee(__('capell-admin::generic.page_setup_description'))
        ->assertSee(__('capell-admin::generic.page_site_info'))
        ->assertSee(__('capell-admin::generic.parent_page_info'))
        ->assertSee(__('capell-admin::form.publish_setup'))
        ->assertSee(__('capell-admin::generic.publish_setup_description'))
        ->assertSee(__('capell-admin::generic.page_layout_select_info'))
        ->assertSee(__('capell-admin::form.publish_from'));
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
        ->assertNotified(__('capell-admin::message.page_language_parent', ['name' => $language2->name]));

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
        $language = Language::factory()->createOne();
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
