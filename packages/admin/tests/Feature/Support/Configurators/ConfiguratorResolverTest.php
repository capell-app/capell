<?php

declare(strict_types=1);

use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Exceptions\ConfiguratorTypeNotFoundException;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Filament\Configurators\Pages\LandingPageConfigurator;
use Capell\Admin\Support\Configurators\ConfiguratorResolver;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;

it('resolves a page type by key within the resource scope', function (): void {
    $type = createType([
        'key' => 'landing-page',
        'group' => BlueprintGroupEnum::Default->value,
    ]);

    $resolved = resolve(ConfiguratorResolver::class)->resolveTypeByKey(
        'landing-page',
        ConfiguratorTypeEnum::Page,
        'default',
    );

    expect($resolved->is($type))->toBeTrue();
});

it('throws a configurator type exception for invalid type keys', function (): void {
    expect(fn () => resolve(ConfiguratorResolver::class)->resolveTypeByKey(
        'missing-page',
        ConfiguratorTypeEnum::Page,
        'default',
    ))->toThrow(
        ConfiguratorTypeNotFoundException::class,
        'Configurator type `missing-page` was not found for Page on resource `default`.',
    );
});

it('resolves the default type when no key is provided', function (): void {
    $type = createType([
        'key' => 'default-page',
        'default' => true,
        'group' => BlueprintGroupEnum::Default->value,
    ]);

    $resolved = resolve(ConfiguratorResolver::class)->resolveDefaultType(
        ConfiguratorTypeEnum::Page,
        'default',
    );

    expect($resolved->is($type))->toBeTrue();
});

it('throws a configurator type exception when the default type is missing', function (): void {
    expect(fn () => resolve(ConfiguratorResolver::class)->resolveDefaultType(
        ConfiguratorTypeEnum::Page,
        'default',
    ))->toThrow(
        ConfiguratorTypeNotFoundException::class,
        'Default configurator type was not found for Page on resource `default`.',
    );
});

it('resolves the configurator class from type admin metadata', function (): void {
    $type = createType([
        'admin' => ['configurator' => LandingPageConfigurator::getKey()],
    ]);

    $configurator = resolve(ConfiguratorResolver::class)->resolveForType(
        $type,
        ConfiguratorTypeEnum::Page,
        DefaultPageConfigurator::getKey(),
    );

    expect($configurator)->toBe(LandingPageConfigurator::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createType(array $attributes = []): Blueprint
{
    return Blueprint::query()->create(array_merge([
        'name' => 'Page Blueprint',
        'key' => 'page-type',
        'type' => BlueprintSubjectEnum::Page->value,
        'default' => false,
        'group' => null,
        'admin' => ['configurator' => DefaultPageConfigurator::getKey()],
        'status' => true,
    ], $attributes));
}
