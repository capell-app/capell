<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Bridges;

use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Database\Eloquent\Model;

interface UserResourceBridge
{
    public const string TAG = 'capell.admin.user_resource_bridges';

    public function supports(UserSchemaContextData $context): bool;

    /**
     * @return array<int, Component>
     */
    public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array;

    /**
     * @return array<int, Component>
     */
    public function extendSidebarComponents(Schema $schema, UserSchemaContextData $context): array;

    /**
     * @param  array<int, class-string<RelationManager>|RelationGroup|RelationManagerConfiguration>  $relationManagers
     * @return array<int, class-string<RelationManager>|RelationGroup|RelationManagerConfiguration>
     */
    public function extendRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mutateDataBeforeCreate(array $data): array;

    public function afterCreate(Model $record): void;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mutateDataBeforeSave(Model $record, array $data): array;

    public function afterSave(Model $record): void;

    /**
     * @return array<int, Column>
     */
    public function columns(): array;

    /**
     * @return array<int, BaseFilter>
     */
    public function filters(): array;

    /**
     * @return array<int, Action>
     */
    public function recordActions(): array;

    /**
     * @return array<int, Action>
     */
    public function toolbarActions(): array;
}
