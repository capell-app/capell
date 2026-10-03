<?php

declare(strict_types=1);

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\LandingPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\ResultsPageConfigurator;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Tests\Fixtures\Autoload\SlugEditorClientForTest;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('page');

/** @param class-string<ConfiguratorInterface> $configurator */
it('preserves a manually chosen URL in a deferred first page draft submission', function (string $configurator, string $interaction): void {
    test()->actingAsAdmin();
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->createOne();
    $blueprint = Blueprint::factory()->page()->admin('configurator', $configurator::getKey())->createOne();
    $layout = Layout::factory()->site($site)->createOne();
    $form = Livewire::test(CreatePage::class)->fillForm([
        'site_id' => $site->getKey(),
        'blueprint_id' => $blueprint->getKey(),
        'layout_id' => $layout->getKey(),
    ]);
    $translations = $form->get('data.translations');
    assert(is_array($translations));
    $key = array_key_first($translations);
    assert($key !== null);
    $path = sprintf('data.translations.%s', $key);
    $client = SlugEditorClientForTest::update($form->html(), $path . '.meta.slug', 'my-chosen-first-page-url', $interaction);

    // A single browser request installs all values, then executes title before
    // slug hooks. Using fillForm() for these values would disable the hooks.
    // Equal Name and Title isolate the URL defect from the separate Name default.
    $form->update(
        calls: [['method' => 'createAsDraft', 'params' => []]],
        updates: [
            'data.name' => 'My first Capell page',
            $path . '.language_id' => $language->getKey(),
            $path . '.title' => 'My first Capell page',
            $path . '.meta.slug' => $client['slug'],
            $path . '.slug_auto_update_disabled' => $client['manual'],
        ],
    )->assertHasNoFormErrors();

    $page = Page::query()->where('blueprint_id', $blueprint->getKey())->sole();
    $translation = $page->translations()->sole();
    expect($page->name)->toBe('My first Capell page')
        ->and($translation->title)->toBe('My first Capell page')
        ->and(data_get($translation->meta, 'slug'))->toBe('my-chosen-first-page-url');
})->with([
    DefaultPageConfigurator::class,
    LandingPageConfigurator::class,
    ResultsPageConfigurator::class,
])->with(['ok', 'enter', 'submit']);

it('keeps automatic URL generation on a deferred first page draft', function (): void {
    test()->actingAsAdmin();
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->createOne();
    $blueprint = Blueprint::factory()->page()->admin('configurator', DefaultPageConfigurator::getKey())->createOne();
    $layout = Layout::factory()->site($site)->createOne();
    $form = Livewire::test(CreatePage::class)->fillForm([
        'site_id' => $site->getKey(),
        'blueprint_id' => $blueprint->getKey(),
        'layout_id' => $layout->getKey(),
    ]);
    $translations = $form->get('data.translations');
    assert(is_array($translations));
    $key = array_key_first($translations);
    assert($key !== null);
    $path = sprintf('data.translations.%s', $key);

    $form->update(
        calls: [['method' => 'createAsDraft', 'params' => []]],
        updates: [
            'data.name' => 'Automatic first page',
            $path . '.language_id' => $language->getKey(),
            $path . '.title' => 'Automatic first page',
        ],
    )->assertHasNoFormErrors();

    $translation = Page::query()->where('blueprint_id', $blueprint->getKey())->sole()->translations()->sole();
    expect($translation->title)->toBe('Automatic first page')
        ->and(data_get($translation->meta, 'slug'))->toBe('automatic-first-page');
});

it('keeps an edited manual URL when title and URL are deferred in the same save', function (): void {
    test()->actingAsAdmin();
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->createOne();
    $blueprint = Blueprint::factory()->page()->admin('configurator', DefaultPageConfigurator::getKey())->createOne();
    $page = Page::factory()->site($site)->type($blueprint)->createOne(['name' => 'Original title']);
    $translation = $page->translations()->save(Translation::factory()->make([
        'language_id' => $language->getKey(),
        'title' => 'Original title',
        'meta' => ['slug' => 'original-url'],
    ]));
    $form = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()]);
    $path = sprintf('data.translations.record-%s', $translation->getKey());
    $client = SlugEditorClientForTest::update($form->html(), $path . '.meta.slug', 'chosen-edited-url', 'ok', manual: true);
    $form->update(
        calls: [['method' => 'save', 'params' => []]],
        updates: [
            $path . '.title' => 'Updated title',
            $path . '.meta.slug' => $client['slug'],
            $path . '.slug_auto_update_disabled' => $client['manual'],
        ],
    )->assertHasNoFormErrors();

    expect($translation->refresh()->title)->toBe('Updated title')
        ->and(data_get($translation->meta, 'slug'))->toBe('chosen-edited-url');
});
