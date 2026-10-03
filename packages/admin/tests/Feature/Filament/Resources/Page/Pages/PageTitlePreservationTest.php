<?php

declare(strict_types=1);

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\LandingPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\ResultsPageConfigurator;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('page');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

/** @param class-string<ConfiguratorInterface> $configurator */
it('preserves the submitted public title when deferred name and title updates are saved as draft', function (string $configurator): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->createOne();
    $blueprint = Blueprint::factory()->page()->admin('configurator', $configurator::getKey())->createOne();
    $layout = Layout::factory()->site($site)->createOne();
    $form = Livewire::test(CreatePage::class)
        ->fillForm([
            'site_id' => $site->getKey(),
            'blueprint_id' => $blueprint->getKey(),
            'layout_id' => $layout->getKey(),
        ]);
    $translations = $form->get('data.translations');
    assert(is_array($translations));
    $translationKey = array_key_first($translations);
    assert($translationKey !== null);

    // One deferred browser request installs all values before any update hook.
    // fillForm() and set(array) cannot reproduce this ordering.
    $form->update(
        calls: [['method' => 'createAsDraft', 'params' => []]],
        updates: [
            'data.name' => 'First user publication check',
            sprintf('data.translations.%s.language_id', $translationKey) => $language->getKey(),
            sprintf('data.translations.%s.title', $translationKey) => 'My first Capell page',
            sprintf('data.translations.%s.meta.slug', $translationKey) => 'my-first-capell-page',
        ],
    )->assertHasNoFormErrors();

    $page = Page::query()->where('blueprint_id', $blueprint->getKey())->sole();
    $translation = $page->translations()->sole();

    expect($page->name)->toBe('First user publication check')
        ->and($translation->title)->toBe('My first Capell page')
        ->and(data_get($translation->meta, 'slug'))->toBe('my-first-capell-page');
})->with([
    DefaultPageConfigurator::class,
    LandingPageConfigurator::class,
    ResultsPageConfigurator::class,
]);

it('seeds a blank internal name and automatic slug from the first public title', function (): void {
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
    $translationKey = array_key_first($translations);
    assert($translationKey !== null);

    $form->update(
        calls: [['method' => 'createAsDraft', 'params' => []]],
        updates: [
            sprintf('data.translations.%s.language_id', $translationKey) => $language->getKey(),
            sprintf('data.translations.%s.title', $translationKey) => 'First public title',
        ],
    )->assertHasNoFormErrors();

    $page = Page::query()->where('blueprint_id', $blueprint->getKey())->sole();
    $translation = $page->translations()->sole();

    expect($page->name)->toBe('First public title')
        ->and($translation->title)->toBe('First public title')
        ->and(data_get($translation->meta, 'slug'))->toBe('first-public-title');
});

/** @param class-string<ConfiguratorInterface> $configurator */
it('keeps the internal name and manual slug when editing the public title', function (string $configurator): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->createOne();
    $blueprint = Blueprint::factory()->page()->admin('configurator', $configurator::getKey())->createOne();
    $page = Page::factory()->site($site)->type($blueprint)->createOne(['name' => 'Internal editorial reference']);
    $translation = $page->translations()->save(Translation::factory()->make([
        'language_id' => $language->getKey(),
        'title' => 'Original public title',
        'meta' => ['slug' => 'deliberate-existing-url'],
    ]));

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->set(sprintf('data.translations.record-%s.title', $translation->getKey()), 'Revised public title')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh()->name)->toBe('Internal editorial reference')
        ->and($translation->refresh()->title)->toBe('Revised public title')
        ->and(data_get($translation->meta, 'slug'))->toBe('deliberate-existing-url');
})->with([
    DefaultPageConfigurator::class,
    LandingPageConfigurator::class,
    ResultsPageConfigurator::class,
]);
