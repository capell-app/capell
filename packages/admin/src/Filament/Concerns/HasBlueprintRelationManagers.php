<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Contracts\Configurators\ProvidesRelationManagers;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Support\Configurators\ConfiguratorResolver;
use Capell\Core\Models\Blueprint;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;

/**
 * @mixin  EditRecord
 */
trait HasBlueprintRelationManagers
{
    /**
     * @return array<int|string, mixed>
     */
    public function getTypeRelationManagers(): array
    {
        /** @var class-string<Blueprint> $model */
        $model = Blueprint::class;

        $data = is_array($this->data) ? $this->data : [];
        $blueprint = $this->record->blueprint ?? $model::query()->find($data['blueprint_id'] ?? null);

        $resource = static::getResource();

        $resourceType = method_exists($resource, 'getResourceType')
            ? $resource::getResourceType()
            : ConfiguratorTypeEnum::fromName(class_basename($this->record));

        $assetType = $resourceType instanceof ConfiguratorTypeEnumInterface
            ? $resourceType
            : ConfiguratorTypeEnum::fromName((string) $resourceType);

        if (! $assetType instanceof ConfiguratorTypeEnumInterface) {
            return [];
        }

        $adminType = $blueprint instanceof Blueprint
            ? resolve(ConfiguratorResolver::class)->resolveForType($blueprint, $assetType, DefaultPageConfigurator::getKey())
            : DefaultPageConfigurator::class;

        if (! is_subclass_of($adminType, ProvidesRelationManagers::class)) {
            return [];
        }

        return $adminType::relationManagers($this->record);
    }

    /**
     * @return array<class-string<RelationManager>|RelationGroup|RelationManagerConfiguration>
     */
    protected function getAllRelationManagers(): array
    {
        $relationManagers = [
            ...$this->getResource()::getRelations(),
            ...$this->getTypeRelationManagers(),
        ];

        $seen = [];

        return array_values(array_filter(
            $relationManagers,
            static function (string|RelationGroup|RelationManagerConfiguration $manager) use (&$seen): bool {
                $key = match (true) {
                    is_string($manager) => $manager,
                    $manager instanceof RelationManagerConfiguration => $manager->relationManager,
                    default => 'group:' . spl_object_id($manager),
                };

                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            },
        ));
    }
}
