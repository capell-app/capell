<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\LanguageSelect;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;

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

    $create = $mounted->getCreateOptionActionForm(Schema::make(Livewire::make()));
    $edit = $mounted->getEditOptionActionForm(Schema::make(Livewire::make()));
    throw_if(! $create instanceof Schema || ! $edit instanceof Schema, RuntimeException::class, 'Nested language forms must return schemas.');

    expect($mounted->getCreateOptionActionName())->toBe('createOption')
        ->and($mounted->getEditOptionActionName())->toBe('editOption')
        ->and($mounted->getCreateOptionActionForm($schema))->not->toBeEmpty()
        ->and($mounted->getEditOptionActionForm($schema))->not->toBeEmpty()
        ->and($create->getModel())->toBe(Language::class)
        ->and($edit->getModel())->toBe(Language::class);
});

it('requires language create permission for the inline create action', function (): void {
    $actor = User::factory()->createOne();
    $actor->givePermissionTo(Permission::findOrCreate('Update:Page', 'web'));

    test()->actingAs($actor);
    $component = LanguageSelect::make('language_id')->withCreateForm();
    $schema = Schema::make(Livewire::make())->statePath('data')->model(Site::class)->components([$component]);
    $mounted = $schema->getComponents()[0];
    assert($mounted instanceof LanguageSelect);
    $action = expectPresent($mounted->getCreateOptionAction());
    expect($action->isAuthorized())->toBeFalse();
    expect(fn () => $action->callBefore())->toThrow(AuthorizationException::class);

    $actor->givePermissionTo(Permission::findOrCreate('Create:Language', 'web'));
    expect($action->isAuthorized())->toBeTrue();
    $action->callBefore();
});

it('requires language update permission for the selected inline edit record', function (): void {
    $language = Language::factory()->createOne();
    $actor = User::factory()->createOne();
    $actor->givePermissionTo(Permission::findOrCreate('Update:Page', 'web'));

    test()->actingAs($actor);
    $component = LanguageSelect::make('language_id')->withRelationship()->withEditForm();
    $schema = Schema::make(Livewire::make()->data(['language_id' => $language->id]))
        ->statePath('data')->model(Site::class)->components([$component]);
    $mounted = $schema->getComponents()[0];
    assert($mounted instanceof LanguageSelect);
    $mounted->state($language->id);
    $action = expectPresent($mounted->getEditOptionAction());
    expect($action->isAuthorized())->toBeFalse();
    expect(fn () => $action->callBefore())->toThrow(AuthorizationException::class);

    $actor->givePermissionTo(Permission::findOrCreate('Update:Language', 'web'));
    expect($action->isAuthorized())->toBeTrue();
    $action->callBefore();
});
