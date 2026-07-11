<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\RepeaterTabs;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Models\Language;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Tanmuhittin\LaravelGoogleTranslate\Translators\ApiTranslate;

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('manages language-backed tabs through the mounted repeater component', function (): void {
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
