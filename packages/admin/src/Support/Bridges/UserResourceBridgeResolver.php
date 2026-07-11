<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Bridges;

use Capell\Admin\Contracts\Bridges\UserResourceBridge;
use Capell\Admin\Contracts\Extenders\UserFormExtender;
use Capell\Admin\Contracts\Extenders\UserTableExtender;
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

        foreach ($this->legacyFormExtenders() as $extender) {
            $data = $extender->mutateDataBeforeCreate($data);
        }

        return $data;
    }

    public function afterCreate(Model $record, UserSchemaContextData $context): void
    {
        foreach ($this->bridges($context) as $bridge) {
            $bridge->afterCreate($record);
        }

        foreach ($this->legacyFormExtenders() as $extender) {
            $extender->afterCreate($record);
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

        foreach ($this->legacyFormExtenders() as $extender) {
            $data = $extender->mutateDataBeforeSave($record, $data);
        }

        return $data;
    }

    public function afterSave(Model $record, UserSchemaContextData $context): void
    {
        foreach ($this->bridges($context) as $bridge) {
            $bridge->afterSave($record);
        }

        foreach ($this->legacyFormExtenders() as $extender) {
            $extender->afterSave($record);
        }
    }

    /** @return array<int, mixed> */
    public function columns(UserSchemaContextData $context): array
    {
        return [
            ...$this->bridges($context)
                ->flatMap(static fn (UserResourceBridge $bridge): array => $bridge->columns())
                ->values()
                ->all(),
            ...$this->legacyTableExtenders()
                ->flatMap(static fn (UserTableExtender $extender): array => $extender->columns())
                ->values()
                ->all(),
        ];
    }

    /** @return array<int, mixed> */
    public function filters(UserSchemaContextData $context): array
    {
        return [
            ...$this->bridges($context)
                ->flatMap(static fn (UserResourceBridge $bridge): array => $bridge->filters())
                ->values()
                ->all(),
            ...$this->legacyTableExtenders()
                ->flatMap(static fn (UserTableExtender $extender): array => $extender->filters())
                ->values()
                ->all(),
        ];
    }

    /** @return array<int, mixed> */
    public function recordActions(UserSchemaContextData $context): array
    {
        return [
            ...$this->bridges($context)
                ->flatMap(static fn (UserResourceBridge $bridge): array => $bridge->recordActions())
                ->values()
                ->all(),
            ...$this->legacyTableExtenders()
                ->flatMap(static fn (UserTableExtender $extender): array => $extender->recordActions())
                ->values()
                ->all(),
        ];
    }

    /** @return array<int, mixed> */
    public function toolbarActions(UserSchemaContextData $context): array
    {
        return [
            ...$this->bridges($context)
                ->flatMap(static fn (UserResourceBridge $bridge): array => $bridge->toolbarActions())
                ->values()
                ->all(),
            ...$this->legacyTableExtenders()
                ->flatMap(static fn (UserTableExtender $extender): array => $extender->toolbarActions())
                ->values()
                ->all(),
        ];
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

    /**
     * @return Collection<int, UserFormExtender>
     */
    private function legacyFormExtenders(): Collection
    {
        return collect(app()->tagged(UserFormExtender::TAG))
            ->filter(static fn (object $extender): bool => $extender instanceof UserFormExtender)
            ->values();
    }

    /**
     * @return Collection<int, UserTableExtender>
     */
    private function legacyTableExtenders(): Collection
    {
        return collect(app()->tagged(UserTableExtender::TAG))
            ->filter(static fn (object $extender): bool => $extender instanceof UserTableExtender)
            ->values();
    }
}
