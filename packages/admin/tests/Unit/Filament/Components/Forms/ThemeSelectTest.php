<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\DefaultToggle;
use Capell\Admin\Filament\Components\Forms\ThemeSelect;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Filament\Schemas\Schema;

it('binds nested theme forms to their own model and selected record', function (): void {
    test()->actingAsAdmin();
    $type = Blueprint::factory()->theme()->default()->create();
    $theme = Theme::factory()->create(['blueprint_id' => $type->getKey()]);
    $component = ThemeSelect::make('theme_id')->withCreateForm()->withEditForm();
    $schema = Schema::make(Livewire::make()->data(['theme_id' => $theme->getKey()]))
        ->statePath('data')->model(Site::class)->components([$component]);
    $mounted = $schema->getComponents()[0];
    assert($mounted instanceof ThemeSelect);
    $mounted->state($theme->getKey());
    $create = $mounted->getCreateOptionActionForm(Schema::make(Livewire::make()));
    $edit = $mounted->getEditOptionActionForm(Schema::make(Livewire::make()));
    throw_if(! $create instanceof Schema || ! $edit instanceof Schema, RuntimeException::class, 'Nested option forms must return schemas.');

    $record = $edit->getRecord();
    throw_unless($record instanceof Theme, RuntimeException::class, 'Nested edit form must retain its selected record.');

    $default = array_find($edit->getFlatComponents(withHidden: true), static fn (mixed $component): bool => $component instanceof DefaultToggle);
    throw_unless($default instanceof DefaultToggle, RuntimeException::class, 'Nested option form must expose its default toggle.');

    expect($default->getLabel())->not->toBeEmpty();

    expect($create->getModel())->toBe(Theme::class)
        ->and($edit->getModel())->toBe(Theme::class)
        ->and($record->getKey())->toBe($theme->getKey());
});
