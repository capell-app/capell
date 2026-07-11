<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\LanguageSelect;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Models\Language;
use Filament\Schemas\Schema;

it('builds ordered language options and defaults to the default language', function (): void {
    $defaultLanguage = Language::factory()->createOne([
        'name' => 'English',
        'code' => 'en',
        'default' => true,
    ]);
    $secondaryLanguage = Language::factory()->createOne([
        'name' => 'French',
        'code' => 'fr',
        'default' => false,
    ]);

    $component = LanguageSelect::make('language_code')
        ->optionKey('code')
        ->withOptions();

    expect($component->getOptions())->toBe([
        $defaultLanguage->code => $defaultLanguage->name,
        $secondaryLanguage->code => $secondaryLanguage->name,
    ])
        ->and($component->getDefaultState())->toBe($defaultLanguage->code);
});

it('defaults multiple language selects to the first available language', function (): void {
    $language = Language::factory()->createOne([
        'name' => 'English',
        'code' => 'en',
        'default' => true,
    ]);

    $component = LanguageSelect::make('language_ids')
        ->multiple()
        ->withOptions();

    expect($component->getDefaultState())->toBe([$language->getKey()]);
});

it('configures create and edit forms used by language relationship selects', function (): void {
    $component = LanguageSelect::make('language_id')
        ->withCreateForm()
        ->withEditForm();
    $schema = Schema::make(Livewire::make())
        ->statePath('data')
        ->components([$component]);
    $mounted = $schema->getComponents()[0];
    assert($mounted instanceof LanguageSelect);

    expect($mounted->getCreateOptionActionName())->toBe('createOption')
        ->and($mounted->getEditOptionActionName())->toBe('editOption')
        ->and($mounted->getCreateOptionActionForm($schema))->not->toBeEmpty()
        ->and($mounted->getEditOptionActionForm($schema))->not->toBeEmpty();
});
