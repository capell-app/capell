<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminZoneContributionData;
use Capell\Admin\Enums\AdminFormActionPositionEnum;
use Capell\Admin\Enums\AdminZone;
use Capell\Admin\Filament\Actions\Page\CreatePageAction;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\AdminZoneRegistry;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(CreatesAdminUser::class)
    ->group('page');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('can save an existing page as draft from the edit page', function (): void {
    $site = Site::factory()->hasSiteDomains()->create();
    $languages = $site->siteDomains->map->language_id;

    $page = Page::factory()->site($site)->create();

    $languages->each(function (int $languageId) use ($page): void {
        $page->translations()->save(
            Translation::factory()->make([
                'language_id' => $languageId,
                'title' => Str::title($page->name . ' ' . $languageId),
            ]),
        );
    });

    $updatedName = 'Updated Draft Name';

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'name' => $updatedName,
        ])
        ->call('saveAsDraft')
        ->assertHasNoFormErrors()
        ->assertNotified();

    assertDatabaseHas(Page::class, ['name' => $updatedName]);
});

it('delegates edit page draft operations to the workspace draft handler when installed', function (): void {
    $page = Page::factory()->withTranslations()->create();
    $handler = new class
    {
        /** @var list<array{method: string, arguments: array<int, mixed>}> */
        public array $calls = [];

        public function saveAsDraft(EditPage $component): void
        {
            $this->calls[] = ['method' => 'saveAsDraft', 'arguments' => [$component->record->getKey()]];
        }

        /** @param array<string, mixed> $location */
        public function saveAsDraftWithLocation(EditPage $component, array $location): void
        {
            $this->calls[] = ['method' => 'saveAsDraftWithLocation', 'arguments' => [$component->record->getKey(), $location]];
        }

        public function deletePageDraft(EditPage $component, int $draftId): void
        {
            $this->calls[] = ['method' => 'deletePageDraft', 'arguments' => [$component->record->getKey(), $draftId]];
        }

        public function redirectToLive(EditPage $component): void
        {
            $this->calls[] = ['method' => 'redirectToLive', 'arguments' => [$component->record->getKey()]];
        }
    };

    app()->instance('capell.workspace.page-draft-handler', $handler);

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertSuccessful()
        ->call('saveAsDraft')
        ->call('saveAsDraftWithLocation', ['site_id' => $page->site_id])
        ->call('deletePageDraft', 123)
        ->call('redirectToLive');

    expect($handler->calls)->toBe([
        ['method' => 'saveAsDraft', 'arguments' => [$page->getKey()]],
        ['method' => 'saveAsDraftWithLocation', 'arguments' => [$page->getKey(), ['site_id' => $page->site_id]]],
        ['method' => 'deletePageDraft', 'arguments' => [$page->getKey(), 123]],
        ['method' => 'redirectToLive', 'arguments' => [$page->getKey()]],
    ]);
});

it('can create a new page as draft from the create page', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();

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
        ->call('createAsDraft')
        ->assertHasNoFormErrors()
        ->assertNotified(__('capell-admin::message.saved_as_draft'));

    assertDatabaseHas(Page::class, [
        'name' => $newData->name,
        'site_id' => $site->id,
    ]);
});

it('can save as draft via CreatePageAction modal from the list page', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->hasSiteDomains()->create();
    $type = Blueprint::factory()->page()->create();

    $newData = Page::factory()->make();
    $slug = str($newData->name)->slug()->toString();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->mountAction(TestAction::make(CreatePageAction::class))
        ->set('mountedActions.0.data.translations', [])
        ->fillForm([
            'site_id' => $site->id,
            'blueprint_id' => $type->id,
            'name' => $newData->name,
        ])
        ->set(
            'mountedActions.0.data.translations',
            $site->languages->mapWithKeys(fn (Language $language): array => [
                (string) Str::uuid() => [
                    'language_id' => $language->getKey(),
                    'title' => $newData->name,
                    'meta' => ['slug' => $slug],
                ],
            ])
                ->toArray(),
        )
        ->callMountedAction(arguments: ['draft' => true])
        ->assertHasNoFormErrors()
        ->assertNotified(__('capell-admin::message.saved_as_draft'));

    assertDatabaseHas(Page::class, [
        'name' => $newData->name,
        'blueprint_id' => $type->id,
        'site_id' => $site->id,
    ]);
});

it('can save as draft via CreatePageAction modal from the edit page', function (): void {
    $page = Page::factory()->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();
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
                    'meta' => ['slug' => $slug],
                ],
            ],
        ])
        ->callMountedAction(arguments: ['draft' => true])
        ->assertHasNoFormErrors()
        ->assertNotified(__('capell-admin::message.saved_as_draft'));

    assertDatabaseHas(Page::class, [
        'name' => $newData->name,
    ]);

    assertDatabaseHas(Page::class, [
        'name' => $page->name,
    ]);
});

it('shows standard notification when normal create is used instead of draft', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->hasSiteDomains()->create();
    $type = Blueprint::factory()->page()->create();

    $newData = Page::factory()->make();
    $slug = str($newData->name)->slug()->toString();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->mountAction(TestAction::make(CreatePageAction::class))
        ->set('mountedActions.0.data.translations', [])
        ->fillForm([
            'site_id' => $site->id,
            'blueprint_id' => $type->id,
            'name' => $newData->name,
        ])
        ->set(
            'mountedActions.0.data.translations',
            $site->languages->mapWithKeys(fn (Language $language): array => [
                (string) Str::uuid() => [
                    'language_id' => $language->getKey(),
                    'title' => $newData->name,
                    'meta' => ['slug' => $slug],
                ],
            ])
                ->toArray(),
        )
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotNotified(__('capell-admin::message.saved_as_draft'));

    assertDatabaseHas(Page::class, [
        'name' => $newData->name,
        'site_id' => $site->id,
    ]);
});

it('offers an ordinary draft save in the configured action position', function (AdminFormActionPositionEnum $position): void {
    $settings = AdminSettings::instance();
    $settings->form_action_position = $position;
    $settings->save();

    $page = Page::factory()->withTranslations()->create(['visible_from' => now()->addCentury(), 'visible_until' => null]);
    $translationFields = ['id', 'language_id', 'translatable_type', 'translatable_id', 'title', 'content'];
    $translations = $page->translations()->get()->map->only($translationFields)->all();
    $slugs = $page->translations()->get()->map(fn (Translation $translation): mixed => data_get($translation->meta, 'slug'))->all();
    $urlFields = ['id', 'site_id', 'language_id', 'pageable_type', 'pageable_id', 'url', 'target_url', 'type'];
    $urls = $page->pageUrls()->get()->map->only($urlFields)->all();

    $livewire = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertActionVisible('saveAsDraft')
        ->fillForm(['name' => 'First draft save'])
        ->callAction('saveAsDraft')
        ->assertHasNoFormErrors()
        ->fillForm(['name' => 'Second draft save'])
        ->callAction('saveAsDraft')
        ->assertHasNoFormErrors();

    $page->refresh();
    expect($page->name)->toBe('Second draft save');
    expect($page->isPending())->toBeTrue();
    expect($page->translations()->get()->map->only($translationFields)->all())->toBe($translations);
    expect($page->translations()->get()->map(fn (Translation $translation): mixed => data_get($translation->meta, 'slug'))->all())->toBe($slugs);
    expect($page->pageUrls()->get()->map->only($urlFields)->all())->toBe($urls);

    $component = $livewire->instance();
    $actions = $position === AdminFormActionPositionEnum::AboveForm
        ? $component->getCachedHeaderActions()
        : $component->getCachedFormActions();
    expect(collect($actions)->filter(fn (Action|ActionGroup $action): bool => $action instanceof Action && $action->getName() === 'saveAsDraft'))->toHaveCount(1);
})->with(AdminFormActionPositionEnum::cases());

it('preserves an extension draft action without adding a duplicate', function (bool $grouped): void {
    resolve(AdminZoneRegistry::class)->register(new AdminZoneContributionData(
        zone: AdminZone::PageEditFormActions,
        key: 'tests.workspace.draft',
        resolver: static function () use ($grouped): array {
            $action = Action::make('saveAsDraft')->label('Workspace draft save');

            return [$grouped ? ActionGroup::make([ActionGroup::make([$action])]) : $action];
        },
    ));
    $page = Page::factory()->withTranslations()->create(['visible_from' => now()->addCentury(), 'visible_until' => null]);

    $livewire = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()]);
    $actions = collect($livewire->instance()->getCachedFormActions())
        ->flatMap(fn (Action|ActionGroup $action): array => $action instanceof ActionGroup ? array_values($action->getFlatActions()) : [$action])
        ->filter(fn (Action $action): bool => $action->getName() === 'saveAsDraft');

    expect($actions)->toHaveCount(1);
    expect($actions->first()->getLabel())->toBe('Workspace draft save');
})->with(['direct' => false, 'nested group' => true]);

it('hides the ordinary draft save for a live published page', function (): void {
    $page = Page::factory()->withTranslations()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertActionHidden('saveAsDraft');
});
