<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Configurators;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Exceptions\ConfiguratorTypeNotFoundException;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Models\Blueprint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ConfiguratorResolver
{
    /**
     * @return class-string<ConfiguratorInterface>
     */
    public function resolveForType(Blueprint $type, ConfiguratorTypeEnumInterface $target, string $fallbackKey): string
    {
        $key = $type->admin['configurator'] ?? $fallbackKey;

        return AdminSurfaceLookup::configurator($target, $key);
    }

    /**
     * @return class-string<ConfiguratorInterface>
     */
    public function resolveForRecord(Model $record, ConfiguratorTypeEnumInterface $target, string $fallbackKey): string
    {
        $blueprint = null;

        if (method_exists($record, 'blueprint')) {
            $record->loadMissing('blueprint');

            $relation = $record->getRelationValue('blueprint');
            $blueprint = $relation instanceof Blueprint ? $relation : null;
        }

        return $blueprint instanceof Blueprint
            ? $this->resolveForType($blueprint, $target, $fallbackKey)
            : AdminSurfaceLookup::configurator($target, $fallbackKey);
    }

    public function resolveTypeByKey(string $key, ConfiguratorTypeEnumInterface $target, ?string $resourceName = null): Blueprint
    {
        $type = $this->typeQuery($target, $resourceName)
            ->where('key', $key)
            ->first();

        throw_if(
            ! $type instanceof Blueprint,
            ConfiguratorTypeNotFoundException::forKey($key, $target, $resourceName),
        );

        return $type;
    }

    public function resolveDefaultType(ConfiguratorTypeEnumInterface $target, ?string $resourceName = null): Blueprint
    {
        $type = $this->typeQuery($target, $resourceName)
            ->default()
            ->first();

        throw_if(
            ! $type instanceof Blueprint,
            ConfiguratorTypeNotFoundException::forDefault($target, $resourceName),
        );

        return $type;
    }

    /**
     * @return Builder<Blueprint>
     */
    private function typeQuery(ConfiguratorTypeEnumInterface $target, ?string $resourceName): Builder
    {
        $query = Blueprint::query()
            ->when($target === ConfiguratorTypeEnum::Page, fn (Builder $query): Builder => $query->pageType())
            ->when($target === ConfiguratorTypeEnum::Site, fn (Builder $query): Builder => $query->siteType())
            ->when($target === ConfiguratorTypeEnum::Theme, fn (Builder $query): Builder => $query->themeType());

        if ($resourceName !== null) {
            $query->adminResource($resourceName);
        }

        return $query;
    }
}
