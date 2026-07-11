<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Filament\Settings\Schemas;

use Capell\Admin\Contracts\DashboardSettingsContributor;
use Capell\Admin\Filament\Components\Forms\DashboardFilamentWidgetSettings;
use Capell\Admin\Filament\Contracts\HasSchema;
use Capell\Admin\Filament\Settings\Schemas\DashboardSettingsSchema;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Tests\Feature\Filament\Settings\Schemas\Fixtures\FixtureDashboardContributor;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Filament\Forms\Components\Hidden;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Mockery;
use ReflectionClass;

uses()->group('admin', 'settings');

it('registers the dashboard settings schema under admin', function (): void {
    $registry = resolve(SettingsSchemaRegistry::class);

    expect($registry->hasGroup('admin'))->toBeTrue()
        ->and($registry->getSettingsClass('admin'))->toBe(AdminSettings::class)
        ->and($registry->getSchemas('admin'))->toHaveKey('DashboardSettingsSchema');
});

it('implements the HasSchema contract', function (): void {
    $interfaces = class_implements(DashboardSettingsSchema::class);

    expect($interfaces)->toContain(HasSchema::class);
});

it('returns form components from make()', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = DashboardSettingsSchema::make($schema);

    expect($components)->toBeArray()->not->toBeEmpty();
});

it('discovers contributor-declared widget toggles via allContributedKeys()', function (): void {
    app()->tag([FixtureDashboardContributor::class], DashboardSettingsContributor::TAG);

    $keys = DashboardSettingsSchema::allContributedKeys();

    expect(collect($keys)->pluck('key')->all())->toContain('fixture_a');
});

it('includes widget descriptions for the dashboard layout editor', function (): void {
    $keys = DashboardSettingsSchema::allContributedKeys();
    $myWorkQueue = collect($keys)->firstWhere('key', 'my_work_queue');
    $myWorkQueue = expectPresent($myWorkQueue);

    expect($myWorkQueue)->toBeArray()
        ->and($myWorkQueue['description'])->toBe(__('capell-admin::dashboard.widget_my_work_queue_description'));
});

it('builds a form section per contributor group plus a tuning section', function (): void {
    app()->tag([FixtureDashboardContributor::class], DashboardSettingsContributor::TAG);

    $schema = Mockery::mock(Schema::class);
    $components = DashboardSettingsSchema::make($schema);

    expect($components)->not->toBeEmpty();
    $hasSection = array_any($components, fn (mixed $component): bool => $component instanceof Section);

    expect($hasSection)->toBeTrue();
});

it('keeps dashboard settings sections contained for readable field contrast', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = DashboardSettingsSchema::make($schema);

    collect($components)
        ->filter(fn (mixed $component): bool => $component instanceof Section)
        ->each(function (Section $section): void {
            expect($section->isContained())->toBeTrue();
        });
});

it('puts the dashboard Filament widget settings editor first', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = DashboardSettingsSchema::make($schema);

    $section = $components[0] ?? null;

    expect($section)->toBeInstanceOf(Section::class);
    assert($section instanceof Section);

    $widgetSettings = collect(sectionChildComponents($section))
        ->first(fn (mixed $child): bool => $child instanceof DashboardFilamentWidgetSettings);

    expect($widgetSettings)->toBeInstanceOf(DashboardFilamentWidgetSettings::class);
});

it('hydrates dashboard Filament widget settings with enabled state and order', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = DashboardSettingsSchema::make($schema);
    $widgetSettings = collect($components)
        ->filter(fn (mixed $component): bool => $component instanceof Section)
        ->flatMap(fn (Section $section): array => sectionChildComponents($section))
        ->first(fn (mixed $child): bool => $child instanceof DashboardFilamentWidgetSettings);

    expect($widgetSettings)->toBeInstanceOf(DashboardFilamentWidgetSettings::class);
    /** @var DashboardFilamentWidgetSettings $widgetSettings */
    expect($widgetSettings->layoutState())->not->toBeEmpty()
        ->and($widgetSettings->layoutState()[0])->toHaveKeys(['key', 'enabled', 'order']);
});

it('shows widget descriptions inside the dashboard layout editor', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = DashboardSettingsSchema::make($schema);
    $widgetSettings = collect($components)
        ->filter(fn (mixed $component): bool => $component instanceof Section)
        ->flatMap(fn (Section $section): array => sectionChildComponents($section))
        ->first(fn (mixed $child): bool => $child instanceof DashboardFilamentWidgetSettings);

    expect($widgetSettings)->toBeInstanceOf(DashboardFilamentWidgetSettings::class);
    assert($widgetSettings instanceof DashboardFilamentWidgetSettings);

    $children = dashboardFilamentWidgetSettingsChildComponents($widgetSettings);

    expect(collect($children)->contains(
        fn (mixed $child): bool => $child instanceof Hidden && filamentObjectName($child) === 'description',
    ))->toBeTrue()
        ->and(collect($children)->contains(
            fn (mixed $child): bool => $child instanceof TextEntry && filamentObjectName($child) === 'description',
        ))->toBeTrue();
});

/**
 * @return array<int|string, mixed>
 */
function sectionChildComponents(Section $section): array
{
    $reflection = new ReflectionClass($section);
    $property = $reflection->getProperty('childComponents');

    return $property->getValue($section)['default'] ?? [];
}

/**
 * @return array<int|string, mixed>
 */
function dashboardFilamentWidgetSettingsChildComponents(DashboardFilamentWidgetSettings $component): array
{
    $reflection = new ReflectionClass($component);
    $property = $reflection->getProperty('childComponents');

    return $property->getValue($component)['default'] ?? [];
}
