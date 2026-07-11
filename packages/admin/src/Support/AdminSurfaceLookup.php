<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use BackedEnum;
use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Exceptions\InvalidResourceTypeException;
use Capell\Admin\Exceptions\ResourceNotFoundException;
use Capell\Admin\Facades\CapellAdmin;
use Filament\Resources\Resource;
use InvalidArgumentException;

final class AdminSurfaceLookup
{
    /**
     * @return class-string<resource>
     */
    public static function resource(BackedEnum|string $group, ?string $name = null): string
    {
        $normalizedGroup = self::resourceGroup($group);
        $normalizedName = self::name($name);
        $resources = CapellAdmin::getAdminSurfaceRegistry()->resourcesForGroup($normalizedGroup);
        $resource = $resources[$normalizedName] ?? null;

        throw_unless(
            $resource !== null,
            ResourceNotFoundException::class,
            'No resources registered for type: ' . $normalizedGroup . ' and name: ' . $normalizedName,
        );

        throw_unless(
            is_subclass_of($resource, Resource::class),
            ResourceNotFoundException::class,
            'Registered resource is not a Filament resource: ' . $resource,
        );

        return $resource;
    }

    /**
     * @return class-string<resource>|null
     */
    public static function resourceIfRegistered(BackedEnum|string $group, ?string $name = null): ?string
    {
        $resources = CapellAdmin::getAdminSurfaceRegistry()->resourcesForGroup(self::resourceGroup($group));

        $resource = $resources[self::name($name)] ?? null;

        return is_string($resource) && is_subclass_of($resource, Resource::class) ? $resource : null;
    }

    /**
     * @return list<string>
     */
    public static function resourceNamesForGroup(BackedEnum|string $group): array
    {
        $normalizedGroup = self::resourceGroup($group);
        $contributions = CapellAdmin::getAdminSurfaceContributions(AdminSurfaceContributionType::Resource);
        $names = [];

        foreach ($contributions as $contribution) {
            if (! $contribution instanceof AdminSurfaceContributionData) {
                continue;
            }

            if ($contribution->group === $normalizedGroup) {
                $names[] = $contribution->name;
            }
        }

        throw_unless(
            $names !== [],
            InvalidResourceTypeException::class,
            sprintf('No resources registered for type: %s', $normalizedGroup),
        );

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, array<string, class-string<ConfiguratorInterface>>>
     */
    public static function configuratorGroups(): array
    {
        $contributions = CapellAdmin::getAdminSurfaceContributions(AdminSurfaceContributionType::Configurator);
        $groups = [];

        foreach ($contributions as $contribution) {
            if (! $contribution instanceof AdminSurfaceContributionData) {
                continue;
            }

            if ($contribution->group === null) {
                continue;
            }

            if (! is_subclass_of($contribution->class, ConfiguratorInterface::class)) {
                continue;
            }

            $groups[$contribution->group][$contribution->name] = $contribution->class;
        }

        return array_filter(
            array_map(self::sortedConfigurators(...), $groups),
            static fn (array $configurators): bool => $configurators !== [],
        );
    }

    /**
     * @return array<string, class-string<ConfiguratorInterface>>
     */
    public static function configurators(BackedEnum|ConfiguratorTypeEnumInterface|string $group): array
    {
        return self::sortedConfigurators(CapellAdmin::getConfigurators(self::configuratorGroup($group)));
    }

    /**
     * @return class-string<ConfiguratorInterface>
     */
    public static function configurator(BackedEnum|ConfiguratorTypeEnumInterface|string $group, BackedEnum|string $name): string
    {
        $normalizedGroup = self::configuratorGroup($group);
        $normalizedName = self::configuratorName($name);
        $configurators = CapellAdmin::getConfigurators($normalizedGroup);
        $configurator = $configurators[$normalizedName] ?? null;

        throw_unless(
            $configurator !== null,
            InvalidArgumentException::class,
            sprintf(
                "No configurator registered for type '%s' with name '%s'. Check that the configurator exists and is registered.",
                $normalizedGroup,
                $normalizedName,
            ),
        );

        throw_unless(
            is_subclass_of($configurator, ConfiguratorInterface::class),
            InvalidArgumentException::class,
            sprintf("Registered configurator '%s' for type '%s' is invalid.", $normalizedName, $normalizedGroup),
        );

        return $configurator;
    }

    public static function hasConfigurator(BackedEnum|ConfiguratorTypeEnumInterface|string $group, BackedEnum|string $name): bool
    {
        $configurators = CapellAdmin::getConfigurators(self::configuratorGroup($group));

        return isset($configurators[self::configuratorName($name)]);
    }

    private static function resourceGroup(BackedEnum|string $group): string
    {
        return $group instanceof BackedEnum ? $group->name : $group;
    }

    private static function configuratorGroup(BackedEnum|ConfiguratorTypeEnumInterface|string $group): string
    {
        if ($group instanceof BackedEnum) {
            return (string) $group->value;
        }

        return $group instanceof ConfiguratorTypeEnumInterface ? $group->getName() : $group;
    }

    private static function configuratorName(BackedEnum|string $name): string
    {
        return $name instanceof BackedEnum ? (string) $name->value : $name;
    }

    private static function name(?string $name): string
    {
        return in_array($name, [null, '', '0'], true) ? 'default' : $name;
    }

    /**
     * @param  array<string, class-string>  $configurators
     * @return array<string, class-string<ConfiguratorInterface>>
     */
    private static function sortedConfigurators(array $configurators): array
    {
        return collect($configurators)
            ->filter(fn (string $configurator): bool => is_subclass_of($configurator, ConfiguratorInterface::class))
            ->unique()
            ->sortBy(function (string $configurator): int {
                if (class_exists($configurator)) {
                    return $configurator::getSort();
                }

                return 0;
            })
            ->all();
    }
}
