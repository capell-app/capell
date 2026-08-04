<?php

declare(strict_types=1);

use Capell\Core\Contracts\SettingsSchema;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Frontend\Filament\Settings\FrontendSettingsSchema;
use Capell\Frontend\Settings\FrontendSettings;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
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

    expect($interfaces)->toContain(SettingsSchema::class);
});

it('frontend settings schema returns form components', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = FrontendSettingsSchema::make($schema);

    expect($components)->toBeArray();
});

it('frontend settings fields are grouped inside contained sections', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = FrontendSettingsSchema::make($schema);

    // The contract is the SHAPE, not the count: nothing may sit loose at the top
    // level, every top-level element is a contained Section, and each section
    // actually holds something. Asserting those directly means adding a section
    // for a genuinely new topic does not require editing this test, while a
    // field escaping to the top level still fails it.
    expect($components)->not->toBeEmpty();

    foreach ($components as $component) {
        // A field that escaped to the top level fails right here.
        expect($component)->toBeInstanceOf(Section::class);
        expect($component->isContained())->toBeTrue();
        expect(rawChildComponents($component))->not->toBeEmpty();
    }
});

it('keeps visitor language detection out of the performance section', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = FrontendSettingsSchema::make($schema);

    $languagesIndex = collect($components)
        ->search(fn (Component $section): bool => collectSchemaComponents(rawChildComponents($section))
            ->contains(fn (Component $component): bool => $component instanceof Select
                && $component->getName() === 'visitor_language_detection'));

    $performanceIndex = collect($components)
        ->search(fn (Component $section): bool => collectSchemaComponents(rawChildComponents($section))
            ->contains(fn (Component $component): bool => $component instanceof Checkbox
                && $component->getName() === 'cache_enabled'));

    // The setting must be reachable in the UI at all...
    expect($languagesIndex)->not->toBeFalse();

    // ...and it changes what a visitor is SHOWN, not how fast it is served, so
    // it deliberately does not live alongside the caching controls.
    expect($languagesIndex)->not->toBe($performanceIndex);
});

it('frontend settings include custom system page toggles', function (): void {
    $settings = resolve(FrontendSettings::class);

    expect($settings->custom_error_page_enabled)->toBeTrue()
        ->and($settings->custom_maintenance_page_enabled)->toBeTrue()
        ->and($settings->scheduled_publication_invalidation_checkpoint)->toBeNull();
});

it('does not overwrite an existing scheduled publication checkpoint when its migration is rerun', function (): void {
    $settings = resolve(FrontendSettings::class);
    $settings->scheduled_publication_invalidation_checkpoint = '2026-07-29T12:00:00+00:00';
    $settings->save();

    $migration = require dirname(__DIR__, 3)
        . '/database/settings/2026_07_29_000001_add_scheduled_publication_invalidation_checkpoint.php';
    $migration->up();

    expect($settings->refresh()->scheduled_publication_invalidation_checkpoint)
        ->toBe('2026-07-29T12:00:00+00:00');
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
