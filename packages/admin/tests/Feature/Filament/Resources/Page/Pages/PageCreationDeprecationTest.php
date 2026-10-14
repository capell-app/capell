<?php

declare(strict_types=1);

use Capell\Admin\Actions\CreatePageAction;
use Capell\Admin\Filament\Components\Forms\PublishDatesGrid;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Schemas\Schema;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('page');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('creates a draft with a public title without PHP deprecations', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->createOne();
    $blueprint = Blueprint::factory()->page()->admin('configurator', DefaultPageConfigurator::getKey())->createOne();
    $layout = Layout::factory()->site($site)->createOne();
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }, E_DEPRECATED);

    try {
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
                sprintf('data.translations.%s.slug_auto_update_disabled', $translationKey) => true,
            ],
        )->assertHasNoFormErrors();

        $page = Page::query()->where('blueprint_id', $blueprint->getKey())->sole();
        $translation = $page->translations()->sole();

        expect($page->name)->toBe('First user publication check')
            ->and($translation->title)->toBe('My first Capell page')
            ->and(data_get($translation->meta, 'slug'))->toBe('my-first-capell-page')
            ->and($page->isPending())->toBeTrue();
    } finally {
        restore_error_handler();
    }
});

it('creates a page programmatically without PHP deprecations', function (): void {
    $site = Site::factory()->createOne();
    $blueprint = Blueprint::factory()->page()->createOne();
    $layout = Layout::factory()->site($site)->createOne();

    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }, E_DEPRECATED);

    try {
        $page = CreatePageAction::run([
            'site_id' => $site->id,
            'blueprint_id' => $blueprint->id,
            'layout_id' => $layout->id,
            'name' => 'Internal reference',
            'translations' => [[
                'language_id' => $site->language_id,
                'title' => 'Public title',
                'content' => '<p>Page content</p>',
                'slug' => 'public-title',
            ]],
        ]);

        expect($page->exists)->toBeTrue()
            ->and($page->translations()->sole()->title)->toBe('Public title');
    } finally {
        restore_error_handler();
    }
});

it('validates publication dates without PHP deprecations', function (string $operation, ?string $from, ?string $until, bool $valid): void {
    $state = ['visible_from' => $from, 'visible_until' => $until];
    // Invalid submissions are checked against rules from a valid mounted picker state.
    $mountedState = $from === 'invalid' ? ['visible_from' => null, 'visible_until' => $until] : $state;
    $schema = Schema::make(Capell\Admin\Tests\Fixtures\Livewire::make()->data($mountedState))
        ->operation($operation)
        ->statePath('data')
        ->components([
            PublishDatesGrid::getVisibleFromField(),
            PublishDatesGrid::getVisibleUntilField(),
        ]);

    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }, E_DEPRECATED);

    try {
        $validator = validator(['data' => $state], $schema->getValidationRules());

        expect($validator->passes())->toBe($valid);
    } finally {
        restore_error_handler();
    }
})->with([
    'blank create dates' => ['create', null, null, true],
    'empty create dates' => ['create', '', '', true],
    'blank edit dates' => ['edit', null, null, true],
    'start only' => ['create', '2020-01-01 12:00:00', null, true],
    'end only' => ['edit', null, '2099-01-01 12:00:00', true],
    'ordered dates' => ['edit', '2020-01-01 12:00:00', '2099-01-01 12:00:00', true],
    'reversed dates' => ['edit', '2020-01-02 12:00:00', '2020-01-01 12:00:00', false],
    'future create date' => ['create', '2099-01-01 12:00:00', null, false],
    'future option create date' => ['createOption', '2099-01-01 12:00:00', null, false],
    'future edit date' => ['edit', '2099-01-01 12:00:00', null, true],
    'invalid date' => ['edit', 'invalid', null, false],
]);
