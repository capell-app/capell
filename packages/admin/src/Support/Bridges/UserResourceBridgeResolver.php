<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Bridges;

use Capell\Admin\Contracts\Bridges\UserResourceBridge;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class UserResourceBridgeResolver
{
    /** @return array<int, mixed> */
    public function resolveComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
    {
        return $this->bridges($context)
            ->flatMap(static fn (UserResourceBridge $bridge): array => $bridge->extendComponentsForHook($schema, $hook, $context))
            ->values()
            ->all();
    }

    /** @return array<int, mixed> */
    public function resolveSidebarComponents(Schema $schema, UserSchemaContextData $context): array
    {
        return $this->bridges($context)
            ->flatMap(static fn (UserResourceBridge $bridge): array => $bridge->extendSidebarComponents($schema, $context))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $relationManagers
     * @return array<int, mixed>
     */
    public function resolveRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array
    {
        foreach ($this->bridges($context) as $bridge) {
            $relationManagers = $bridge->extendRelationManagers($record, $relationManagers, $context);
        }

        return collect($relationManagers)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mutateDataBeforeCreate(array $data, UserSchemaContextData $context): array
    {
        foreach ($this->bridges($context) as $bridge) {
            $data = $bridge->mutateDataBeforeCreate($data);
        }

        return $data;
    }

    public function afterCreate(Model $record, UserSchemaContextData $context): void
    {
        foreach ($this->bridges($context) as $bridge) {
            $bridge->afterCreate($record);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mutateDataBeforeSave(Model $record, array $data, UserSchemaContextData $context): array
    {
        foreach ($this->bridges($context) as $bridge) {
            $data = $bridge->mutateDataBeforeSave($record, $data);
        }

        return $data;
    }

    public function afterSave(Model $record, UserSchemaContextData $context): void
    {
        foreach ($this->bridges($context) as $bridge) {
            $bridge->afterSave($record);
        }
    }

    /** @return array<int, mixed> */
    public function columns(UserSchemaContextData $context): array
    {
        return $this->bridges($context)
            ->flatMap(static fn (UserResourceBridge $bridge): array => $bridge->columns())
            ->values()
            ->all();
    }

    /** @return array<int, mixed> */
    public function filters(UserSchemaContextData $context): array
    {
        return $this->bridges($context)
            ->flatMap(static fn (UserResourceBridge $bridge): array => $bridge->filters())
            ->values()
            ->all();
    }

    /** @return array<int, mixed> */
    public function recordActions(UserSchemaContextData $context): array
    {
        return $this->bridges($context)
            ->flatMap(static fn (UserResourceBridge $bridge): array => $bridge->recordActions())
            ->values()
            ->all();
    }

    /** @return array<int, mixed> */
    public function toolbarActions(UserSchemaContextData $context): array
    {
        return $this->bridges($context)
            ->flatMap(static fn (UserResourceBridge $bridge): array => $bridge->toolbarActions())
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, UserResourceBridge>
     */
    private function bridges(UserSchemaContextData $context): Collection
    {
        return collect(app()->tagged(UserResourceBridge::TAG))
            ->filter(static fn (object $bridge): bool => $bridge instanceof UserResourceBridge && $bridge->supports($context))
            ->values();
    }
}
