<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Users;

use BackedEnum;
use Capell\Admin\Enums\NavigationGroupEnum;
use Capell\Admin\Filament\Concerns\HasConfiguredForm;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Filament\Concerns\HasNavigationBadge;
use Capell\Admin\Filament\Resources\Users\Pages\CreateUser;
use Capell\Admin\Filament\Resources\Users\Pages\EditUser;
use Capell\Admin\Filament\Resources\Users\Pages\ListUsers;
use Capell\Admin\Filament\Resources\Users\Schemas\UserForm;
use Capell\Admin\Filament\Resources\Users\Tables\UsersTable;
use Capell\Admin\Support\Users\SiteUserVisibility;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Override;
use UnitEnum;

class UserResource extends Resource
{
    use HasConfiguredForm;
    use HasConfiguredTable;
    use HasNavigationBadge;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Users;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string $formConfigurator = UserForm::class;

    protected static string $tableConfigurator = UsersTable::class;

    protected static ?int $navigationSort = -70;

    /**
     * @return class-string<Authenticatable>
     */
    #[Override]
    public static function getModel(): string
    {
        return config('auth.providers.users.model');
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return SiteUserVisibility::scope(parent::getEloquentQuery(), auth()->user());
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) (__('capell-admin::navigation.users'));
    }

    #[Override]
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return NavigationGroupEnum::Users;
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getRelations(): array
    {
        return [];
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return __('capell-admin::model_labels.users');
    }
}
