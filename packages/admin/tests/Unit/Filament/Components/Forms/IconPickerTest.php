<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\IconPicker;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures\IconPickerLivewire;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire as LivewireTest;

it('keeps the parent placeholder and does not reject icons from sets the host has not registered', function (): void {
    $component = IconPicker::make('icon');
    Schema::make(Livewire::make())->statePath('data')->components([$component])->getComponents();

    expect($component->getLabel())->toBe(__('capell-admin::form.icon'))
        ->and($component->getPlaceholder())->toBe(__('filament-icon-picker::icon-picker.placeholder'))
        ->and(Validator::make(['icon' => 'heroicon-o-home'], ['icon' => $component->getValidationRules()])->passes())->toBeTrue()
        ->and(Validator::make(['icon' => 'fab-facebook-f'], ['icon' => $component->getValidationRules()])->passes())->toBeTrue();
});

it('resolves registered icons through the methods called by the rendered picker', function (): void {
    $component = IconPicker::make('icon');
    Schema::make(Livewire::make()->data(['icon' => 'heroicon-o-home']))
        ->statePath('data')
        ->components([$component])
        ->getComponents();

    expect($component->getState())->toBe('heroicon-o-home')
        ->and($component->getDisplayName())->toBeString()->not->toBeEmpty()
        ->and($component->verifyState('unregistered-icon'))->toBeNull()
        ->and($component->verifyState())->toBeNull();
});

it('renders the picker and exposes its callbacks through the schema dispatcher', function (): void {
    $livewire = LivewireTest::test(IconPickerLivewire::class)
        ->assertSuccessful()
        ->assertSeeHtml('iconPickerComponent(')
        ->assertSeeHtml('data.icon')
        ->assertSeeHtml('<svg');

    $instance = $livewire->instance();
    assert($instance instanceof IconPickerLivewire);
    $component = $instance->form->getComponents()[0];
    assert($component instanceof IconPicker);
    $key = $component->getKey();
    assert(is_string($key));

    expect($component->verifyState('heroicon-o-home'))->toBe('heroicon-o-home')
        ->and($component->verifyState('unregistered-icon'))->toBeNull();

    // Version 4 serves the picker through Livewire callbacks; version 5 serves it over HTTP routes.
    if (method_exists($component, 'getSetJs')) {
        expect($instance->callSchemaComponentMethod($key, 'getSetJs', ['state' => 'heroicon-o-home']))->toBe('heroicons')
            ->and(collect($instance->callSchemaComponentMethod($key, 'getIconsJs', ['set' => 'heroicons']))->pluck('id')->all())->toContain('heroicon-o-home')
            ->and($instance->callSchemaComponentMethod($key, 'getIconSvgJs', ['id' => 'heroicon-o-home']))->toContain('<svg');
    }
});
