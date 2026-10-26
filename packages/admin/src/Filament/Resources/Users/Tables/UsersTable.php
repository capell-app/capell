<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Users\Tables;

use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Filament\Components\Tables\Actions\EditAction;
use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\NameColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Bridges\UserResourceBridgeResolver;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;
use Spatie\Permission\Models\Role;
use STS\FilamentImpersonate\Actions\Impersonate;

class UsersTable implements TableConfigurator
{
    #[Override]
    public static function configure(Table $table): Table
    {
        // Keep the rendering snapshot on this table, never across requests.
        $scopedRoleIds = null;

        return $table
            ->columns([
                NameColumn::make('name'),
                TextColumn::make('roles.name')
                    ->label(__('capell-admin::table.role'))
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()->toString())
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('email')
                    ->label(__('capell-admin::table.email'))
                    ->sortable()
                    ->searchable()
                    ->url(fn (string $state): string => 'mailto:' . $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bio')
                    ->label(__('capell-admin::table.biography'))
                    ->searchable()
                    ->limit()
                    ->formatStateUsing(static fn (?string $state): string => strip_tags((string) $state))
                    ->toggleable(isToggledHiddenByDefault: true),
                DateColumn::make('email_verified_at')
                    ->label(__('capell-admin::table.email_verified_at'))
                    ->sortable()
                    ->searchable()
                    ->size('sm')
                    ->alignRight()
                    ->width(0)
                    ->toggleable(isToggledHiddenByDefault: true),
                DateColumn::make('created_at'),
                DateColumn::make('updated_at'),
                ...self::extenderColumns(),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->label(__('capell-admin::table.role')),
                TernaryFilter::make('email_verified_at')
                    ->label(__('capell-admin::table.email_verified_at'))
                    ->trueLabel(__('capell-admin::table.verified'))
                    ->falseLabel(__('capell-admin::table.unverified'))
                    ->nullable(),
                ...self::extenderFilters(),
            ])
            ->recordActions([
                Impersonate::make()
                    ->label(__('capell-admin::button.act_as_owner'))
                    ->hidden(fn (): bool => ! self::supportActionsBridgeEnabled()),
                ...self::extenderRecordActions(),
                EditAction::make()->label(__('capell-admin::button.edit_user')),
                ActionGroup::make([
                    Action::make('edit-role')
                        ->label(__('capell-admin::button.edit_role_permissions'))
                        ->modalDescription(__('capell-admin::generic.shared_role_permissions_notice'))
                        ->icon('heroicon-o-shield-check')
                        ->visible(function (Model $record) use (&$scopedRoleIds): bool {
                            $scopedRoleIds ??= array_values(RoleResource::getEloquentQuery()->pluck('id')
                                ->filter(fn (mixed $id): bool => is_int($id) || is_string($id))
                                ->all());

                            return self::editableRoles($record, $scopedRoleIds) !== [];
                        })
                        ->schema([
                            Select::make('role_id')
                                ->label(__('capell-admin::table.role'))
                                ->options(fn (Model $record): array => self::editableRoleOptions($record))
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (array $data, Model $record, Action $action): void {
                            $role = collect(self::editableRoles($record))
                                ->firstWhere('id', (int) $data['role_id']);

                            abort_unless($role instanceof Role, 403);

                            $action->redirect(RoleResource::getUrl('edit', ['record' => $role]));
                        }),
                ])
                    ->color('gray'),
            ])
            ->toolbarActions([
                ...self::extenderToolbarActions(),
                DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
            ])
            ->emptyStateHeading(__('capell-admin::generic.no_users'))
            ->emptyStateDescription(__('capell-admin::generic.no_users_description'))
            ->emptyStateIcon('heroicon-o-users');
    }

    /**
     * @return array<int, Column>
     */
    private static function extenderColumns(): array
    {
        return resolve(UserResourceBridgeResolver::class)->columns(self::userSchemaContext());
    }

    /**
     * @return array<int, BaseFilter>
     */
    private static function extenderFilters(): array
    {
        return resolve(UserResourceBridgeResolver::class)->filters(self::userSchemaContext());
    }

    /**
     * @return array<int, Action>
     */
    private static function extenderRecordActions(): array
    {
        return resolve(UserResourceBridgeResolver::class)->recordActions(self::userSchemaContext());
    }

    /**
     * @return array<int, Action>
     */
    private static function extenderToolbarActions(): array
    {
        return resolve(UserResourceBridgeResolver::class)->toolbarActions(self::userSchemaContext());
    }

    private static function userSchemaContext(): UserSchemaContextData
    {
        return UserSchemaContextData::forCreate(resourceName: 'users');
    }

    private static function supportActionsBridgeEnabled(): bool
    {
        return AdminSettings::instance()->enable_support_actions_user_bridge;
    }

    /**
     * @param  list<int|string>|null  $scopedIds
     * @return list<Role>
     */
    private static function editableRoles(Model $record, ?array $scopedIds = null): array
    {
        $roles = $record->getRelationValue('roles');

        if (! $roles instanceof Collection) {
            return [];
        }

        // Options and submission recheck scope; only row visibility uses the snapshot.
        $scopedIds ??= RoleResource::getEloquentQuery()->whereKey($roles->modelKeys())->pluck('id')->all();

        return array_values(
            $roles
                ->filter(fn (mixed $role): bool => $role instanceof Role && in_array($role->getKey(), $scopedIds, true) && RoleResource::canEdit($role))
                ->all(),
        );
    }

    /** @return array<int, string> */
    private static function editableRoleOptions(Model $record): array
    {
        return collect(self::editableRoles($record))
            ->mapWithKeys(fn (Role $role): array => [$role->getKey() => $role->name . ' — ' . (
                $role->getAttribute((string) config('permission.column_names.team_foreign_key', 'team_id')) === null
                    ? __('capell-admin::generic.role_scope_global')
                    : __('capell-admin::generic.role_scope_site', ['site' => $role->getAttribute((string) config('permission.column_names.team_foreign_key', 'team_id'))])
            )])
            ->all();
    }
}
