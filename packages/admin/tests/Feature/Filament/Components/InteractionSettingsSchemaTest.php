<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Interactions\InteractionSettingsSchema;
use Capell\Frontend\Contracts\DeferredFragmentReferenceBuilder;

it('builds a progressive interaction schema for widget and fragment targets', function (): void {
    $schema = InteractionSettingsSchema::make();

    expect($schema)->toHaveCount(1);
});

it('can be scoped to layout builder block metadata', function (): void {
    $schema = InteractionSettingsSchema::make('interactions');

    expect($schema)->toHaveCount(1);
});

it('hides the fragment interaction target while no deferred fragment reference builder is installed', function (): void {
    $options = InteractionSettingsSchema::targetOptions();

    expect($options)->not->toHaveKey('fragment')
        ->and($options)->toHaveKeys(['widget', 'url', 'public_action']);
});

it('offers the fragment interaction target when a deferred fragment reference builder is installed', function (): void {
    app()->instance(DeferredFragmentReferenceBuilder::class, new stdClass);

    $options = InteractionSettingsSchema::targetOptions();

    expect($options)->toHaveKey('fragment')
        ->and($options)->toHaveKeys(['widget', 'fragment', 'url', 'public_action']);
});
