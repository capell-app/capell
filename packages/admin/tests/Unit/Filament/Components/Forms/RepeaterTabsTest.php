<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\RepeaterTabs;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Models\Language;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tanmuhittin\LaravelGoogleTranslate\Translators\ApiTranslate;

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('manages language-backed tabs through the mounted repeater component', function (): void {
    config()->set('laravel_google_translate.google_translate_api_key', 'test-api-key');

    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $german = Language::factory()->german()->createOne();

    $translator = Mockery::mock(ApiTranslate::class);
    $translator
        ->shouldReceive('translate')
        ->andReturnUsing(fn (string $text, string $locale, ?string $baseLocale = null): string => sprintf('%s:%s:%s', $baseLocale, $locale, $text));
    app()->instance(ApiTranslate::class, $translator);

    $translations = [
        'english-tab' => [
            'language_id' => $english->getKey(),
            'title' => 'Hello',
            'meta' => [
                'description' => 'Welcome',
            ],
        ],
        'french-tab' => [
            'language_id' => $french->getKey(),
            'title' => '',
            'meta' => [
                'description' => '',
            ],
        ],
    ];

    $component = mountedRepeaterTabs(
        RepeaterTabs::make('translations')
            ->tabs([
                TextInput::make('title'),
                TextInput::make('meta.description'),
            ])
            ->createItems([
                ['id' => $english->getKey()],
                ['id' => $french->getKey()],
                ['id' => $german->getKey()],
            ])
            ->itemBadge(fn (array $state): ?string => $state['title'] ?? null)
            ->itemIcon(fn (array $state): string => filled($state['title'] ?? null) ? 'heroicon-o-language' : 'heroicon-o-document')
            ->minimal(fn (): bool => true)
            ->persistTabInQueryString('translation-tab'),
        $translations,
    );

    expect($component->getCreateItems())->toHaveCount(3)
        ->and($component->isMinimal())->toBeTrue()
        ->and($component->getView())->toBe('capell-admin::components.schemas.repeater-tabs-minimal')
        ->and($component->isTabPersistedInQueryString())->toBeTrue()
        ->and($component->getTabQueryStringKey())->toBe('translation-tab')
        ->and($component->getDefaultTab())->toBe(1)
        ->and($component->getItemBadge('english-tab'))->toBe('Hello')
        ->and($component->getItemIcon('english-tab'))->toBe('heroicon-o-language');

    $component->getAddAllAction()->call([
        'component' => $component,
    ]);

    $stateAfterAddAll = $component->getState();

    expect(collect($stateAfterAddAll)->pluck('language_id')->all())
        ->toContain($english->getKey(), $french->getKey(), $german->getKey());

    $component->activeTab(1);
    $component->translateAction()->call([
        'component' => $component,
    ]);

    $translatedState = $component->getState();
    $frenchRow = collect($translatedState)->firstWhere('language_id', $french->getKey());

    expect($frenchRow['title'])->toBe('en:fr:Hello')
        ->and($frenchRow['meta']['description'])->toBe('en:fr:Welcome')
        ->and($frenchRow['meta']['slug'])->toBe(Str::slug('en:fr:Hello'));

    $component->cloneRepeaterTab(tab: 1, languageId: $german->getKey());

    expect($component->getState())->toHaveCount(6);

    $component->deleteRepeaterTab(tab: 2);

    expect($component->getState())->toHaveCount(5);
});

it('honours persisted query-string tab selection and blocked add callbacks', function (): void {
    request()->query->set('translation-tab', 'second-tab');

    $component = mountedRepeaterTabs(
        RepeaterTabs::make('translations')
            ->tabs([
                TextInput::make('title'),
            ])
            ->createItems([
                ['id' => 1],
                ['id' => 2],
            ])
            ->beforeAddAction(fn (): bool => false)
            ->persistTabInQueryString('translation-tab'),
        [
            'first-tab' => ['language_id' => 1, 'title' => 'One'],
            'second-tab' => ['language_id' => 2, 'title' => 'Two'],
        ],
    );

    expect($component->getDefaultTab())->toBe(2)
        ->and($component->getActiveTab())->toBe(2);

    $component->getAddAction()->call([
        'component' => $component,
        'arguments' => [
            'language_id' => 3,
            'title' => 'Three',
        ],
    ]);

    expect($component->getState())->toHaveCount(2);
});

it('uses the only available create item when add action arguments are missing', function (): void {
    $component = mountedRepeaterTabs(
        RepeaterTabs::make('translations')
            ->tabs([
                TextInput::make('title'),
            ])
            ->createItems([
                ['id' => 3],
            ]),
        [
            'first-tab' => ['language_id' => 1, 'title' => 'One'],
            'second-tab' => ['language_id' => 2, 'title' => 'Two'],
        ],
    );

    $component->getAddAction()->call([
        'component' => $component,
    ]);

    expect(collect($component->getState())->pluck('language_id')->all())->toBe([1, 2, 3]);
});

it('uses a valid request language when add action arguments are missing', function (): void {
    request()->query->set('language_id', '4');

    $component = mountedRepeaterTabs(
        RepeaterTabs::make('translations')
            ->tabs([
                TextInput::make('title'),
            ])
            ->createItems([
                ['id' => 3],
                ['id' => 4],
            ]),
        [
            'first-tab' => ['language_id' => 1, 'title' => 'One'],
            'second-tab' => ['language_id' => 2, 'title' => 'Two'],
        ],
    );

    $component->getAddAction()->call([
        'component' => $component,
    ]);

    expect(collect($component->getState())->pluck('language_id')->all())->toBe([1, 2, 4]);
});

it('prepares tab and create-item icon presentation outside the Blade view', function (): void {
    $component = mountedRepeaterTabs(
        RepeaterTabs::make('translations')
            ->tabs([TextInput::make('title')])
            ->itemBadge(fn (): array => ['label' => 'Draft', 'color' => 'warning'])
            ->itemIcon(fn (): string => 'flag-gb'),
        ['english-tab' => ['language_id' => 1, 'title' => 'One']],
    );

    expect($component->getTabPresentation('english-tab'))->toBe([
        'badge' => 'Draft',
        'badgeColor' => 'warning',
        'badgeTooltip' => null,
        'icon' => 'flag-gb',
        'isFlagIcon' => true,
        'label' => null,
    ])->and($component->getCreateItemIcon(['icon' => 'flag-fr']))->toBeNull()
        ->and($component->getCreateItemIcon(['icon' => 'heroicon-o-language']))->toBe('heroicon-o-language')
        ->and($component->getCreateItemIcon([]))->toBeNull();
});

it('disables the auto-translate action until translation api credentials are configured', function (): void {
    config()->set('capell-admin.auto_translate_language_text', true);
    config()->set('laravel_google_translate', [
        'google_translate_api_key' => null,
        'yandex_translate_api_key' => null,
        'custom_api_translator' => null,
    ]);

    $component = mountedRepeaterTabs(
        RepeaterTabs::make('translations')->tabs([TextInput::make('title')]),
        ['english-tab' => ['language_id' => 1, 'title' => 'One']],
    );

    expect($component->hasTranslationApiCredentials())->toBeFalse();

    $action = $component->translateAction();

    expect($action->isVisible())->toBeTrue()
        ->and($action->isDisabled())->toBeTrue()
        ->and($action->getTooltip())->toBe(__('capell-admin::generic.auto_translate_unavailable_info'));

    config()->set('laravel_google_translate.google_translate_api_key', 'test-api-key');

    $enabledAction = $component->translateAction();

    expect($component->hasTranslationApiCredentials())->toBeTrue()
        ->and($enabledAction->isDisabled())->toBeFalse()
        ->and($enabledAction->getTooltip())->toBe(__('capell-admin::generic.auto_translate_info'));
});

it('keeps the auto-translate action available for a custom api translator', function (): void {
    config()->set('laravel_google_translate', [
        'google_translate_api_key' => null,
        'yandex_translate_api_key' => null,
        'custom_api_translator' => 'App\\Translators\\CustomApiTranslator',
    ]);

    $component = mountedRepeaterTabs(
        RepeaterTabs::make('translations')->tabs([TextInput::make('title')]),
        ['english-tab' => ['language_id' => 1, 'title' => 'One']],
    );

    expect($component->hasTranslationApiCredentials())->toBeTrue()
        ->and($component->translateAction()->isDisabled())->toBeFalse();
});

it('hides the auto-translate action when the admin config disables it', function (): void {
    config()->set('capell-admin.auto_translate_language_text', false);

    $component = mountedRepeaterTabs(
        RepeaterTabs::make('translations')->tabs([TextInput::make('title')]),
        ['english-tab' => ['language_id' => 1, 'title' => 'One']],
    );

    expect($component->translateAction()->isVisible())->toBeFalse();
});

/**
 * @param  array<string, array<string, mixed>>  $state
 */
function mountedRepeaterTabs(RepeaterTabs $component, array $state): RepeaterTabs
{
    $livewire = Livewire::make()->data([
        'translations' => $state,
    ]);

    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([$component]);

    $mounted = $schema->getComponents()[0];
    assert($mounted instanceof RepeaterTabs);

    $mounted->state($state);

    return $mounted;
}

it('keeps missing editor state paths available while preserving values through item re-keying', function (): void {
    $values = ['language_id' => 4, 'title' => 'French title', 'meta' => ['slug' => 'french-title', 'sentinel' => false, 'count' => 0, 'blank' => '']];
    $component = mountedRepeaterTabs(
        RepeaterTabs::make('translations')->tabs([
            TextInput::make('title'),
            TextInput::make('meta.description'),
            TextInput::make('meta.hero'),
            TextInput::make('meta.summary'),
            TextInput::make('meta.sentinel'),
            TextInput::make('meta.count'),
            TextInput::make('meta.blank'),
        ]),
        ['new-french' => $values],
    );

    foreach (['new-french', 'record-42'] as $itemKey) {
        $component->state([$itemKey => $values]);
        $item = $component->getItems()[$itemKey];
        $state = $item->getRawState();

        expect($state)->toBe([
            ...$values,
            'meta' => [...$values['meta'], 'description' => null, 'hero' => null, 'summary' => null],
        ]);
    }
});

it('renders independent projections with unique identities and valid panel labels', function (bool $minimal): void {
    view()->share('errors', new ViewErrorBag);
    $livewire = Livewire::make()->data([
        'translations' => [
            'record-42' => ['title' => 'French title', 'meta' => ['description' => 'French description']],
            'record-43' => ['title' => 'English title', 'meta' => ['description' => 'English description']],
        ],
    ]);
    $schema = Schema::make($livewire)->statePath('data')->components([
        RepeaterTabs::make('translations')->key('content-editor')
            ->tabs([TextInput::make('title')])->itemLabel(fn (array $state): string => $state['title'])
            ->addable(false)->deletable(false)->minimal($minimal),
        RepeaterTabs::make('translations')->key('seo-editor')
            ->tabs([TextInput::make('meta.description')])->itemLabel(fn (array $state): string => $state['title'])
            ->addable(false)->deletable(false)->minimal($minimal),
    ]);

    $document = new DOMDocument;
    $html = $schema->toHtml();
    throw_if($html === '', RuntimeException::class, 'The repeater schema rendered no HTML.');
    $previousLibxmlErrors = libxml_use_internal_errors(true);
    try {
        $document->loadHTML($html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlErrors);
    }

    $xpath = new DOMXPath($document);
    $ids = [];
    $keys = [];
    $idElements = $xpath->query('//*[@id]');
    $keyElements = $xpath->query('//*[@*[name()="wire:key"]]');
    $panels = $xpath->query('//*[@role="tabpanel"]');
    throw_unless($idElements !== false && $keyElements !== false && $panels !== false, RuntimeException::class, 'Invalid repeater DOM query.');
    foreach ($idElements as $element) {
        throw_unless($element instanceof DOMElement, RuntimeException::class, 'Expected an element with an ID.');
        $ids[] = $element->getAttribute('id');
    }

    foreach ($keyElements as $element) {
        throw_unless($element instanceof DOMElement, RuntimeException::class, 'Expected an element with a Livewire key.');
        $keys[] = $element->getAttribute('wire:key');
    }

    expect($ids)->not->toBeEmpty()->toHaveCount(count(array_unique($ids)))
        ->and($keys)->not->toBeEmpty()->toHaveCount(count(array_unique($keys)))
        ->and($panels->length)->toBe(4);

    foreach ($panels as $panel) {
        throw_unless($panel instanceof DOMElement, RuntimeException::class, 'Expected a panel element.');
        if ($minimal) {
            expect($panel->getAttribute('aria-label'))->not->toBeEmpty();
        } else {
            $labelId = $panel->getAttribute('aria-labelledby');
            expect($ids)->toContain($labelId);
            $labels = $xpath->query(sprintf('//*[@id="%s"]', $labelId));
            throw_if($labels === false, RuntimeException::class, 'Invalid panel label query.');
            $label = $labels->item(0);
            throw_unless($label instanceof DOMElement, RuntimeException::class, 'The panel label does not exist.');
            expect($label->getAttribute('aria-controls'))->toBe($panel->getAttribute('id'));
        }
    }
})->with([false, true]);

it('completes nested fields when their metadata container is entirely absent', function (): void {
    $component = mountedRepeaterTabs(
        RepeaterTabs::make('translations')->tabs([
            Group::make([TextInput::make('description')])->statePath('meta'),
        ]),
        ['new-french' => ['language_id' => 4]],
    );

    expect($component->getItems()['new-french']->getRawState())
        ->toBe(['language_id' => 4, 'meta' => ['description' => null]]);
});
