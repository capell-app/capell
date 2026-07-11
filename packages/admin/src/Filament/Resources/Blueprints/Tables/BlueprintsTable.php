<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Blueprints\Tables;

use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Admin\Filament\Components\Tables\Actions\EditAction;
use Capell\Admin\Filament\Components\Tables\Actions\ReplicateAction;
use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Components\Tables\Columns\NameColumn;
use Capell\Admin\Filament\Components\Tables\Columns\StatusIconColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Resources\Blueprints\Pages\ManageBlueprints;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class BlueprintsTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $sql = '(CASE';
                foreach (CapellCore::getPageTypes() as $type) {
                    if ($type->name === 'type') {
                        continue;
                    }

                    $table = resolve($type->model)->getTable();

                    $sql .= sprintf(
                        " WHEN `type` = '%s' THEN (SELECT COUNT(*) FROM `%s` WHERE blueprints.id = `%s`.blueprint_id)",
                        $type->name,
                        $table,
                        $table,
                    );
                }

                $sql .= " ELSE 0 END) AS 'count'";

                return $query->select([
                    'blueprints.*',
                    DB::raw(self::literalSql($sql)),
                ]);
            })
            ->defaultSort('type')
            ->columns(static::getTableColumns())
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::ScreenLarge)
                    ->slideOver()
                    ->hidden(
                        /**
                         * @param  Model&SoftDeletes  $record
                         */
                        fn (Blueprint $record): bool => $record->trashed(),
                    )
                    ->modalHeading(
                        fn (?Blueprint $record): string => __(
                            'capell-admin::heading.edit_type_record',
                            ['type' => $record instanceof Blueprint ? self::recordTypeName($record) : ''],
                        ),
                    )
                    ->mutateRecordDataUsing(function (array $data, Blueprint $record): array {
                        // Fix issue where type is cast to DTO
                        $data['type'] = self::recordTypeName($record);

                        return $data;
                    })
                    ->using(self::updateBlueprint(...)),
                ActionGroup::make([
                    ReplicateAction::make()
                        ->hidden(
                            /**
                             * @param  Model&SoftDeletes  $record
                             */
                            fn (Blueprint $record): bool => $record->trashed(),
                        ),
                    DeleteAction::make()
                        ->before(function (ManageBlueprints $livewire, DeleteAction $action, Blueprint $record): void {
                            if (! $livewire->validateDelete($record)) {
                                $action->cancel();
                            }
                        }),
                    RestoreAction::make(),
                ])
                    ->color('gray'),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->before(function (ManageBlueprints $livewire, DeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records): void {
                        $records->each(function (Blueprint $record) use ($livewire, $action): void {
                            if (! $livewire->validateDelete($record)) {
                                $action->cancel();
                            }
                        });
                    }),
                ForceDeleteBulkAction::make(),
                RestoreBulkAction::make(),
            ])
            ->emptyStateHeading(__('capell-admin::generic.no_blueprints'))
            ->emptyStateDescription(__('capell-admin::generic.no_blueprints_description'))
            ->emptyStateIcon('heroicon-o-squares-2x2');
    }

    /** @param array<string, mixed> $data */
    public static function updateBlueprint(Blueprint $record, array $data): Blueprint
    {
        $roleRestrictions = $data['admin']['role_restrictions'] ?? null;
        unset($data['admin']['role_restrictions']);

        $record->update($data);

        if (auth()->user()?->can('manageRestrictions', Page::class) === true && is_array($roleRestrictions)) {
            $record->syncRoleRestrictions(
                array_values(array_map(intval(...), $roleRestrictions)),
            );
        }

        return $record;
    }

    public static function recordTypeName(Blueprint $record): string
    {
        $type = $record->getRawOriginal('type');

        if (is_string($type) && $type !== '') {
            return $type;
        }

        $type = $record->getAttribute('type');

        return $type instanceof PageTypeData ? $type->name : '';
    }

    /**
     * @return array<int, mixed>
     */
    protected static function getTableColumns(): array
    {
        return [
            IdentifierColumn::make('id'),
            NameColumn::make('name')
                ->icon(fn (Blueprint $record): string => (string) ($record->admin['icon'] ?? ''))
                ->description(fn (Blueprint $record): ?string => $record->admin['notes'] ?? null)
                ->defaultBadge()
                ->searchable([
                    'name',
                    'admin->notes',
                    'component',
                ]),
            TextColumn::make('type')
                ->label(__('capell-admin::table.type'))
                ->state(fn (Blueprint $record): string => self::recordTypeName($record))
                ->hidden(fn (ManageBlueprints $livewire): bool => $livewire->activeTab !== 'all')
                ->weight(FontWeight::SemiBold)
                ->searchable()
                ->sortable()
                ->badge()
                ->toggleable(),
            TextColumn::make('key')
                ->label(__('capell-admin::table.key'))
                ->searchable()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('group')
                ->label(__('capell-admin::table.group'))
                ->toggleable(),
            TextColumn::make('admin.type_configurator')
                ->label(__('capell-admin::table.admin_configurator'))
                ->size('xs')
                ->color(FilamentColorEnum::LightGray->value)
                ->toggleable(isToggledHiddenByDefault: true)
                ->searchable(query: self::applyAdminTypeConfiguratorSearch(...)),
            TextColumn::make('admin.configurator')
                ->label(__('capell-admin::table.configurator'))
                ->size('xs')
                ->color(FilamentColorEnum::LightGray->value)
                ->toggleable(isToggledHiddenByDefault: true)
                ->searchable(query: self::applyAdminConfiguratorSearch(...)),
            TextColumn::make('component')
                ->label(__('capell-admin::table.component'))
                ->size('xs')
                ->color(FilamentColorEnum::LightGray->value)
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('count')
                ->label(__('capell-admin::table.total'))
                ->alignCenter()
                ->sortable()
                ->color('primary')
                ->numeric()
                ->url(fn (?int $state, Blueprint $record): ?string => self::resourceIndexUrlForBlueprintType($record)),
            StatusIconColumn::make('status'),
            DateColumn::make('created_at'),
            DateColumn::make('updated_at'),
            DateColumn::make('deleted_at'),
        ];
    }

    protected static function resourceIndexUrlForBlueprintType(Blueprint $record): ?string
    {
        $type = self::recordTypeName($record);

        if ($type === '') {
            return null;
        }

        $resource = AdminSurfaceLookup::resourceIfRegistered(ucfirst($type));

        if ($resource === null) {
            return null;
        }

        return $resource::getUrl(
            'index',
            ['filters' => ['blueprint_id' => ['value' => $record->id]]],
        );
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected static function applyAdminTypeConfiguratorSearch(Builder $query, string $search): void
    {
        self::applyAdminJsonSearch($query, 'admin->type_configurator', $search);
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected static function applyAdminConfiguratorSearch(Builder $query, string $search): void
    {
        self::applyAdminJsonSearch($query, 'admin->configurator', $search);
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected static function applyAdminJsonSearch(Builder $query, string $column, string $search): void
    {
        /** @var Connection $databaseConnection */
        $databaseConnection = $query->getConnection();

        $searchOperator = match ($databaseConnection->getDriverName()) {
            'pgsql' => 'ilike',
            default => 'like',
        };

        $query->where($column, $searchOperator, sprintf('%%%s%%', $search));
    }

    /**
     * @return literal-string
     */
    private static function literalSql(string $sql): string
    {
        /** @var literal-string $sql */
        return $sql;
    }
}
