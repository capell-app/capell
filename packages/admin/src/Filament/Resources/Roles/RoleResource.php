<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Roles;

use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use Capell\Admin\Filament\Resources\Roles\Pages\CreateRole;
use Capell\Admin\Filament\Resources\Roles\Pages\EditRole;
use Capell\Admin\Filament\Resources\Roles\Pages\ListRoles;
use Capell\Admin\Filament\Resources\Roles\Pages\ViewRole;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;
use RuntimeException;
use Spatie\Permission\Models\Role;
use UnitEnum;

class RoleResource extends ShieldRoleResource
{
    #[Override]
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return null;
    }

    #[Override]
    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    #[Override]
    public static function getNavigationParentItem(): ?string
    {
        return (string) __('capell-admin::navigation.users');
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->recordActions([
                EditAction::make(),
                self::updatePermissionsAction(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ])
            ->emptyStateHeading(__('capell-admin::generic.no_roles'))
            ->emptyStateDescription(__('capell-admin::generic.no_roles_description'))
            ->emptyStateIcon('heroicon-o-shield-check');
    }

    #[Override]
    public static function getSelectAllFormComponent(): Toggle
    {
        $component = parent::getSelectAllFormComponent();

        throw_unless($component instanceof Toggle, RuntimeException::class);

        return $component
            ->label(__('capell-admin::generic.role_select_all_permissions'))
            ->helperText(
                __('capell-admin::generic.role_select_all_permissions_help') . ' ' .
                __('capell-admin::generic.role_permissions_search_help') . ' ' .
                __('capell-admin::generic.role_select_all_permissions_clear_warning'),
            );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    private static function updatePermissionsAction(): Action
    {
        return Action::make('updatePermissions')
            ->label(__('capell-admin::button.update_permissions'))
            ->icon(Heroicon::ShieldCheck)
            ->authorize('update')
            ->url(fn (Role $record): string => static::getUrl('edit', ['record' => $record]));
    }
}
