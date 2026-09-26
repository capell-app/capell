<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Layout\GroupSelect;
use Capell\Admin\Filament\Components\Forms\Page\UrlParamsRepeater;
use Capell\Admin\Filament\Components\Forms\ThemeSelect;
use Capell\Admin\Tests\Fixtures\Livewire;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

it('writes a created layout group to the resolved Livewire state path', function (): void {
    $component = GroupSelect::make('group');
    $createForm = $component->getCreateOptionActionForm(Schema::make(Livewire::make()));

    throw_unless(is_array($createForm), RuntimeException::class, 'Expected an array of create-form components.');

    $schema = Schema::make(Livewire::make())
        ->statePath('mountedActionData')
        ->components($createForm);
    $groupInput = $schema->getComponents()[0];

    throw_unless($groupInput instanceof TextInput, RuntimeException::class, 'Expected the group input.');

    $handler = $groupInput->getExtraAlpineAttributes()['x-on:keyup'] ?? null;
    $encodedStatePath = json_encode($groupInput->getStatePath(true), JSON_THROW_ON_ERROR);

    expect($handler)
        ->toBeString()
        ->toContain('$wire.$set(' . $encodedStatePath . ', $event.target.value)')
        ->not->toContain('{$component->getStatePath(true)}');
});

it('assigns the theme form to the create option action only', function (): void {
    $component = ThemeSelect::make('theme_id')->withCreateForm();

    expect($component->hasCreateOptionActionFormSchema())->toBeTrue()
        ->and($component->hasEditOptionActionFormSchema())->toBeFalse();
});

it('converts URL parameter maps to repeater rows and back', function (): void {
    $livewire = Livewire::make()->data([
        'url_params' => ['year' => 'int', 'slug' => 'string'],
    ]);
    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([UrlParamsRepeater::make('url_params')]);
    $component = $schema->getComponents()[0];

    throw_unless($component instanceof UrlParamsRepeater, RuntimeException::class, 'Expected the URL parameter repeater.');

    $component->callAfterStateHydrated();

    expect($component->getState())->toBe([
        ['key' => 'year', 'value' => 'int'],
        ['key' => 'slug', 'value' => 'string'],
    ])->and($component->mutateDehydratedState([
        ['key' => 'year', 'value' => 'int'],
        ['key' => 'slug', 'value' => 'string'],
    ]))->toBe([
        'year' => 'int',
        'slug' => 'string',
    ]);
});
