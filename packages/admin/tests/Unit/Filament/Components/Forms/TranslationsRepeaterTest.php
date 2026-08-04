<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Page\TranslationsRepeater as PageTranslationsRepeater;
use Capell\Admin\Filament\Components\Forms\TranslationsRepeater;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures\PageTranslationsRepeaterLivewireForTest;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Carbon\CarbonInterface;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;

it('enforces required site translation languages from mounted admin form state', function (): void {
    $english = Language::factory()->english()->createOne();
    $welsh = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls', order: 2)->createOne();
    $french = Language::factory()->french(order: 3)->createOne();

    $site = Site::factory()
        ->language($english)
        ->withTranslations([$english, $welsh])
        ->createOne([
            'admin' => [
                'require_translations' => [$english->code, $welsh->code],
            ],
        ]);

    $blueprint = Blueprint::factory()->page()->createOne([
        'admin' => [
            'require_translations' => [$english->code, $welsh->code],
        ],
    ]);

    $component = mountedBaseTranslationsRepeaterForTest([
        'site_id' => $site->getKey(),
        'blueprint_id' => $blueprint->getKey(),
        'translations' => [
            'english-row' => [
                'language_id' => $english->getKey(),
            ],
        ],
    ]);

    $failures = [];
    $translationRule = collect($component->getValidationRules())
        ->first(fn (mixed $rule): bool => $rule instanceof Closure);
    assert($translationRule instanceof Closure);

    $translationRule(
        'data.translations',
        [['language_id' => $english->getKey()]],
        function (string $message) use (&$failures): void {
            $failures[] = $message;
        },
    );

    expect($component->isRequired())->toBeTrue()
        ->and($component->getLabel())->toBe('Translations')
        ->and($component->getMinItems())->toBe(2)
        ->and($component->isAddable())->toBeTrue()
        ->and($component->isCloneable())->toBeTrue()
        ->and($component->getItemLabel('english-row'))->toBe('English')
        ->and($component->getItemIcon('english-row'))->toBe('flag-4x3-gb-eng')
        ->and($component->getCreateItems())->toMatchArray([
            [
                'id' => $welsh->getKey(),
                'label' => 'Welsh',
                'icon' => 'flag-4x3-gb-wls',
            ],
            [
                'id' => $french->getKey(),
                'label' => 'Français',
                'icon' => 'flag-4x3-fr',
            ],
        ])
        ->and($failures)->toBe([
            'The following required languages are missing: Welsh.',
        ]);
});

it('scopes page translation defaults and addable languages to the current site', function (): void {
    $english = Language::factory()->english()->createOne();
    $welsh = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls', order: 2)->createOne();
    $german = Language::factory()->german(order: 3)->createOne();

    $site = Site::factory()
        ->language($english)
        ->withTranslations([$english, $welsh])
        ->createOne();
    $otherSite = Site::factory()
        ->language($german)
        ->withTranslations($german)
        ->createOne();

    $blueprint = Blueprint::factory()->page()->createOne();
    $page = Page::factory()->site($site)->type($blueprint)->createOne();
    Page::factory()->site($otherSite)->type($blueprint)->createOne();

    $component = mountedPageTranslationsRepeaterForTest([
        'site_id' => $site->getKey(),
        'translations' => [
            'english-row' => [
                'language_id' => $english->getKey(),
            ],
        ],
    ], $page);

    $defaultState = $component->getDefaultState();

    $defaultLanguageIds = collect($defaultState)
        ->pluck('language_id')
        ->all();

    expect($defaultLanguageIds)->toContain($english->getKey(), $welsh->getKey(), $german->getKey())
        ->and($component->getCreateItems())->toBe([
            [
                'id' => $welsh->getKey(),
                'label' => 'Welsh',
                'icon' => 'flag-4x3-gb-wls',
            ],
        ])
        ->and($component->isAddable())->toBeTrue()
        ->and($component->getItemLabel('english-row'))->toBe('English');

    $component->state([
        'english-row' => ['language_id' => $english->getKey()],
        'welsh-row' => ['language_id' => $welsh->getKey()],
    ]);

    expect($component->isAddable())->toBeFalse();
});

it('uses the page content structure override when preparing translation content', function (): void {
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()
        ->language($language)
        ->createOne();
    $blueprint = Blueprint::factory()
        ->page()
        ->contentStructure(ContentStructure::Html)
        ->createOne();
    $page = Page::factory()
        ->site($site)
        ->type($blueprint)
        ->createOne();
    $page->setAttribute('content_structure_override', ContentStructure::Blocks->value);

    $blocks = [
        ['type' => 'content', 'data' => ['content' => '<p>Converted page body.</p>']],
    ];

    $component = mountedPageTranslationsRepeaterForTest([
        'site_id' => $site->getKey(),
        'content_structure_override' => ContentStructure::Blocks->value,
        'translations' => [],
    ], $page);

    $mutatedData = $component->mutateTranslationRowBeforeFill([
        'language_id' => $language->getKey(),
        'content' => $blocks,
    ]);

    expect($mutatedData['content'])->toBe($blocks);
});

it('blocks page translation additions that would violate parent language coverage', function (): void {
    $english = Language::factory()->english()->createOne();
    $welsh = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls', order: 2)->createOne();

    $site = Site::factory()
        ->language($english)
        ->withTranslations([$english, $welsh])
        ->createOne();

    $blueprint = Blueprint::factory()->page()->createOne();
    $parent = Page::factory()->site($site)->type($blueprint)->createOne(['name' => 'Parent page']);
    $parent->translations()->save(Translation::factory()->language($english)->make());

    $page = Page::factory()->site($site)->type($blueprint)->createOne();

    $component = mountedNestedPageTranslationsRepeaterForTest([
        'site_id' => $site->getKey(),
        'parent_id' => $parent->getKey(),
        'translations' => [
            'english-row' => [
                'language_id' => $english->getKey(),
            ],
        ],
    ], $page);

    $addAction = $component->getAddAction();

    expect(fn (): mixed => $addAction->call([
        'component' => $component,
        'arguments' => [
            'language_id' => 0,
        ],
    ]))->toThrow(Halt::class);

    $addAction->call([
        'component' => $component,
        'arguments' => [
            'language_id' => $english->getKey(),
        ],
    ]);

    expect($component->getState())->toHaveCount(1);

    expect(fn (): mixed => $addAction->call([
        'component' => $component,
        'arguments' => [
            'language_id' => $welsh->getKey(),
        ],
    ]))->toThrow(Halt::class);

    expect($component->getState())->toHaveCount(1);

    $componentWithoutResolvableParent = mountedNestedPageTranslationsRepeaterForTest([
        'site_id' => $site->getKey(),
        'parent_id' => $parent->getKey() + 1000,
        'translations' => [
            'english-row' => [
                'language_id' => $english->getKey(),
            ],
        ],
    ], $page);

    $componentWithoutResolvableParent->getAddAction()->call([
        'component' => $componentWithoutResolvableParent,
        'arguments' => [
            'language_id' => $welsh->getKey(),
        ],
    ]);

    expect($componentWithoutResolvableParent->getState())->toHaveCount(2);
});

it('adds the only available page translation language when add action arguments are missing', function (): void {
    $english = Language::factory()->english()->createOne();
    $welsh = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls', order: 2)->createOne();

    $site = Site::factory()
        ->language($english)
        ->withTranslations([$english, $welsh])
        ->createOne();

    $blueprint = Blueprint::factory()->page()->createOne();
    $page = Page::factory()->site($site)->type($blueprint)->createOne();

    $component = mountedPageTranslationsRepeaterForTest([
        'site_id' => $site->getKey(),
        'translations' => [
            'english-row' => [
                'language_id' => $english->getKey(),
            ],
        ],
    ], $page);

    expect($component->getCreateItems()[0]['id'] ?? null)->toBe($welsh->getKey());

    expect($component->resolveAddActionData([]))->toBe([
        'language_id' => $welsh->getKey(),
    ]);
});

it('flags a translation whose default language was edited more recently', function (): void {
    $english = Language::factory()->english()->createOne();
    $welsh = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls', order: 2)->createOne();

    $site = Site::factory()->language($english)->createOne();
    $blueprint = Blueprint::factory()->page()->createOne();
    $page = Page::factory()->site($site)->type($blueprint)->createOne();

    $welshTranslation = savedTranslationForTest($page, $welsh, 'Croeso', now()->subDay());
    $englishTranslation = savedTranslationForTest($page, $english, 'Welcome', now());

    $component = mountedBaseTranslationsRepeaterForTest([
        'site_id' => $site->getKey(),
        'blueprint_id' => $blueprint->getKey(),
        'translations' => [],
    ]);

    expect($englishTranslation->getRawOriginal('updated_at'))
        ->toBeGreaterThan($welshTranslation->getRawOriginal('updated_at'));

    expect($component->resolveItemBadge($welshTranslation, ['title' => null]))->toBe([
        'label' => __('capell-admin::generic.translation_outdated'),
        'color' => 'warning',
        'tooltip' => __('capell-admin::generic.translation_outdated_info'),
    ]);
});

it('does not report a freshly cloned language row as complete', function (): void {
    $english = Language::factory()->english()->createOne();
    $welsh = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls', order: 2)->createOne();

    $site = Site::factory()->language($english)->createOne();
    $blueprint = Blueprint::factory()->page()->createOne();
    $page = Page::factory()->site($site)->type($blueprint)->createOne();

    $clonedAt = now();
    $englishTranslation = savedTranslationForTest($page, $english, 'Welcome', $clonedAt);
    $clonedTranslation = savedTranslationForTest($page, $welsh, 'Welcome', $clonedAt);

    $component = mountedBaseTranslationsRepeaterForTest([
        'site_id' => $site->getKey(),
        'blueprint_id' => $blueprint->getKey(),
        'translations' => [],
    ]);

    expect($englishTranslation->getRawOriginal('updated_at'))
        ->toBe($clonedTranslation->getRawOriginal('updated_at'));

    expect($component->resolveItemBadge($clonedTranslation, ['title' => null]))->toBe([
        'label' => __('capell-admin::generic.translation_untranslated'),
        'color' => 'gray',
        'tooltip' => __('capell-admin::generic.translation_untranslated_info'),
    ]);
});

it('reserves the success badge for a translated and current language row', function (): void {
    $english = Language::factory()->english()->createOne();
    $welsh = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls', order: 2)->createOne();

    $site = Site::factory()->language($english)->createOne();
    $blueprint = Blueprint::factory()->page()->createOne();
    $page = Page::factory()->site($site)->type($blueprint)->createOne();

    $englishTranslation = savedTranslationForTest($page, $english, 'Welcome', now()->subDay());
    $welshTranslation = savedTranslationForTest($page, $welsh, 'Croeso', now());

    $component = mountedBaseTranslationsRepeaterForTest([
        'site_id' => $site->getKey(),
        'blueprint_id' => $blueprint->getKey(),
        'translations' => [],
    ]);

    expect($welshTranslation->getRawOriginal('updated_at'))
        ->toBeGreaterThan($englishTranslation->getRawOriginal('updated_at'));

    expect($component->resolveItemBadge($welshTranslation, ['title' => null]))->toBe([
        'label' => __('capell-admin::generic.translation_complete'),
        'color' => 'success',
        'tooltip' => __('capell-admin::generic.translation_complete_info'),
    ])->and($component->resolveItemBadge($englishTranslation, ['title' => null]))->toBeNull();
});

it('resolves the missing required languages failure through the translator', function (): void {
    expect(__('capell-admin::message.required_translation_languages_missing', ['languages' => 'Welsh']))
        ->toBe('The following required languages are missing: Welsh.');
});

/**
 * TranslationFactory seeds a random `updated_at`, and Eloquent preserves an
 * already-dirty timestamp on save, so staleness fixtures must pin the column on
 * every row they compare rather than relying on save order.
 */
function savedTranslationForTest(Page $page, Language $language, string $title, CarbonInterface $updatedAt): Translation
{
    $translation = Translation::factory()
        ->language($language)
        ->make([
            'title' => $title,
            'updated_at' => $updatedAt,
        ]);

    $page->translations()->save($translation);

    return $translation->refresh();
}

/**
 * @param  array<string, mixed>  $state
 */
function mountedBaseTranslationsRepeaterForTest(array $state): TranslationsRepeater
{
    $schema = Schema::make(Livewire::make()->data($state))
        ->statePath('data')
        ->operation('edit')
        ->components([
            TranslationsRepeater::make('translations')->withoutRelationship(),
        ]);

    $component = $schema->getComponents()[0];
    assert($component instanceof TranslationsRepeater);
    $component->state((array) ($state['translations'] ?? []));
    clearRepeaterRelationshipForTranslationsRepeaterTest($component);

    return $component;
}

/**
 * @param  array<string, mixed>  $state
 */
function mountedPageTranslationsRepeaterForTest(array $state, Page $page): PageTranslationsRepeater
{
    $livewire = new PageTranslationsRepeaterLivewireForTest;
    $livewire->data($state);

    $schema = Schema::make($livewire)
        ->statePath('data')
        ->operation('edit')
        ->model($page)
        ->components([
            PageTranslationsRepeater::make('translations')->withoutRelationship(),
        ]);

    $component = $schema->getComponents()[0];
    assert($component instanceof PageTranslationsRepeater);
    $component->state((array) ($state['translations'] ?? []));
    clearRepeaterRelationshipForTranslationsRepeaterTest($component);

    return $component;
}

/**
 * @param  array<string, mixed>  $state
 */
function mountedNestedPageTranslationsRepeaterForTest(array $state, Page $page): PageTranslationsRepeater
{
    $livewire = new PageTranslationsRepeaterLivewireForTest;
    $livewire->data($state);

    $schema = Schema::make($livewire)
        ->statePath('data')
        ->operation('edit')
        ->model($page)
        ->components([
            Section::make('page')
                ->schema([
                    PageTranslationsRepeater::make('translations')->withoutRelationship(),
                ]),
        ]);

    $section = $schema->getComponents()[0];
    assert($section instanceof Section);

    $childSchema = $section->getChildSchema();
    assert($childSchema instanceof Schema);

    $component = $childSchema->getComponents()[0];
    assert($component instanceof PageTranslationsRepeater);
    $component->state((array) ($state['translations'] ?? []));
    clearRepeaterRelationshipForTranslationsRepeaterTest($component);

    return $component;
}

function clearRepeaterRelationshipForTranslationsRepeaterTest(Repeater $component): void
{
    $relationship = new ReflectionProperty(Repeater::class, 'relationship');
    $relationship->setValue($component, null);
}
