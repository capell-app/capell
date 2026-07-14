<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Interactions\InteractionSettingsSchema;
use Capell\Frontend\Contracts\Fragments\PublicFragmentUrlResolver;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
use Capell\Frontend\Support\Fragments\PublicFragmentUrlResolverRegistry;

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

it('offers the fragment interaction target when a public fragment owner is registered', function (): void {
    app()->instance(PublicFragmentUrlResolverRegistry::class, new PublicFragmentUrlResolverRegistry([
        new class implements PublicFragmentUrlResolver
        {
            public function owner(): string
            {
                return 'layout-builder';
            }

            public function url(PublicFragmentReferenceData $reference): string
            {
                return '/_fragments/' . $reference->contentVersion;
            }
        },
    ]));

    $options = InteractionSettingsSchema::targetOptions();

    expect($options)->toHaveKey('fragment')
        ->and($options)->toHaveKeys(['widget', 'fragment', 'url', 'public_action']);
});
