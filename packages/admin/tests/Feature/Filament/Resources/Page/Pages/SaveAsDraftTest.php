<?php

declare(strict_types=1);

use Capell\Admin\Filament\Actions\Page\CreatePageAction;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
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
