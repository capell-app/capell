<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Interactions\InteractionSettingsSchema;
use Capell\Core\Contracts\InteractionTargetCapabilityContributor;
use Capell\Core\Enums\InteractionTargetType;

it('builds a progressive interaction schema for widget and fragment targets', function (): void {
    $schema = InteractionSettingsSchema::make();

    expect($schema)->toHaveCount(1);
});

it('can be scoped to layout builder block metadata', function (): void {
    $schema = InteractionSettingsSchema::make('interactions');

    expect($schema)->toHaveCount(1);
});

it('hides the fragment interaction target while no public fragment owner is registered', function (): void {
    $options = InteractionSettingsSchema::targetOptions();

    expect($options)->not->toHaveKey('fragment')
        ->and($options)->toHaveKeys(['widget', 'url', 'public_action']);
});

it('hides the fragment interaction target when every valid contributor declines it', function (): void {
    $contributor = new class implements InteractionTargetCapabilityContributor
    {
        public function supports(InteractionTargetType $targetType): bool
        {
            return false;
        }
    };
    app()->instance($contributor::class, $contributor);
    app()->tag($contributor::class, InteractionTargetCapabilityContributor::TAG);

    expect(InteractionSettingsSchema::targetOptions())->not->toHaveKey('fragment');
});

it('offers the fragment interaction target when any valid contributor supports it', function (): void {
    $decliningContributor = new class implements InteractionTargetCapabilityContributor
    {
        public function supports(InteractionTargetType $targetType): bool
        {
            return false;
        }
    };
    $supportingContributor = new class implements InteractionTargetCapabilityContributor
    {
        public function supports(InteractionTargetType $targetType): bool
        {
            return $targetType === InteractionTargetType::Fragment;
        }
    };
    $invalidContributor = new stdClass;
    app()->instance($decliningContributor::class, $decliningContributor);
    app()->instance($supportingContributor::class, $supportingContributor);
    app()->instance($invalidContributor::class, $invalidContributor);
    app()->tag([
        $decliningContributor::class,
        $invalidContributor::class,
        $supportingContributor::class,
    ], InteractionTargetCapabilityContributor::TAG);

    $options = InteractionSettingsSchema::targetOptions();

    expect($options)->toHaveKey('fragment')
        ->and($options)->toHaveKeys(['widget', 'fragment', 'url', 'public_action']);
});

it('ignores invalid interaction target capability tag entries', function (): void {
    $invalidContributor = new stdClass;
    app()->instance($invalidContributor::class, $invalidContributor);
    app()->tag($invalidContributor::class, InteractionTargetCapabilityContributor::TAG);

    expect(InteractionSettingsSchema::targetOptions())->not->toHaveKey('fragment');
});
