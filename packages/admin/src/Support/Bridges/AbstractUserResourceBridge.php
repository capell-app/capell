<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Bridges;

use Capell\Admin\Contracts\Bridges\UserResourceBridge;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractUserResourceBridge implements UserResourceBridge
{
    public function supports(UserSchemaContextData $context): bool
    {
        return true;
    }

    public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
    {
        return [];
    }

    public function extendSidebarComponents(Schema $schema, UserSchemaContextData $context): array
    {
        return [];
    }

    /**
     * @param  array<int, class-string<RelationManager>|RelationGroup|RelationManagerConfiguration>  $relationManagers
     * @return array<int, class-string<RelationManager>|RelationGroup|RelationManagerConfiguration>
     */
    public function extendRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array
    {
        return $relationManagers;
    }

    public function mutateDataBeforeCreate(array $data): array
    {
        return $data;
    }

    public function afterCreate(Model $record): void
    {
        //
    }

    public function mutateDataBeforeSave(Model $record, array $data): array
    {
        return $data;
    }

    public function afterSave(Model $record): void
    {
        //
    }

    public function columns(): array
    {
        return [];
    }

    public function filters(): array
    {
        return [];
    }

    public function recordActions(): array
    {
        return [];
    }

    public function toolbarActions(): array
    {
        return [];
    }
}
