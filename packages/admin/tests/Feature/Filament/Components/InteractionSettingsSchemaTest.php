<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Interactions\InteractionSettingsSchema;

it('builds a progressive interaction schema for widget and fragment targets', function (): void {
    $schema = InteractionSettingsSchema::make();

    expect($schema)->toHaveCount(1);
});

it('can be scoped to layout builder block metadata', function (): void {
    $schema = InteractionSettingsSchema::make('interactions');

    expect($schema)->toHaveCount(1);
});
