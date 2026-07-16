<?php

declare(strict_types=1);

use Capell\Admin\Filament\Contracts\HasSchema;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Frontend\Filament\Settings\FrontendSettingsSchema;
use Capell\Frontend\Settings\FrontendSettings;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Forms\Components\Checkbox;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

uses(CreatesAdminUser::class)
    ->group('frontend', 'settings');

it('registers frontend settings schema in registry', function (): void {
    $registry = resolve(SettingsSchemaRegistry::class);

    expect($registry->hasGroup('frontend'))->toBeTrue()
        ->and($registry->getSettingsClass('frontend'))->toBe(FrontendSettings::class)
        ->and($registry->getSchemas('frontend'))->toHaveKey('FrontendSettingsSchema');
});

it('frontend settings schema implements hasschema contract', function (): void {
    $interfaces = class_implements(FrontendSettingsSchema::class);

    expect($interfaces)->toContain(HasSchema::class);
});

it('frontend settings schema returns form components', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = FrontendSettingsSchema::make($schema);

    expect($components)->toBeArray();
});

it('frontend settings fields are grouped inside contained sections', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = FrontendSettingsSchema::make($schema);

    expect($components)
        ->toHaveCount(1)
        ->each->toBeInstanceOf(Section::class);

    foreach ($components as $component) {
        expect($component->isContained())->toBeTrue();
    }
});

it('frontend settings include custom system page toggles', function (): void {
    $settings = resolve(FrontendSettings::class);

    expect($settings->custom_error_page_enabled)->toBeTrue()
        ->and($settings->custom_maintenance_page_enabled)->toBeTrue();
});

it('frontend admin settings schema exposes custom system page toggles', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = FrontendSettingsSchema::make($schema);

    $checkboxNames = collectSchemaComponents($components)
        ->filter(fn (Component $component): bool => $component instanceof Checkbox)
        ->map(fn (Checkbox $component): string => $component->getName())
        ->all();

    expect($checkboxNames)
        ->toContain('custom_error_page_enabled')
        ->toContain('custom_maintenance_page_enabled')
        ->not->toContain('custom_system_pages_enabled');
});

/**
 * @param  array<int, Component>  $components
 * @return Collection<int, Component>
 */
function collectSchemaComponents(array $components): Collection
{
    return collect($components)->flatMap(fn (Component $component): array => [
        $component,
        ...collectSchemaComponents(rawChildComponents($component))->all(),
    ])->values();
}

/**
 * @return array<int, Component>
 */
function rawChildComponents(Component $component): array
{
    $reflection = new ReflectionClass($component);

    while (! $reflection->hasProperty('childComponents')) {
        $parent = $reflection->getParentClass();

        if ($parent === false) {
            return [];
        }

        $reflection = $parent;
    }

    $property = $reflection->getProperty('childComponents');

    return collect($property->getValue($component))
        ->flatten(1)
        ->filter(fn (mixed $child): bool => $child instanceof Component)
        ->values()
        ->all();
}
