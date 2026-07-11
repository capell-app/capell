<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Redirects;

use BackedEnum;
use Capell\Admin\Filament\Concerns\HasConfiguredForm;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Filament\Resources\Redirects\Pages\ManageRedirects;
use Capell\Admin\Filament\Resources\Redirects\Schemas\RedirectForm;
use Capell\Admin\Filament\Resources\Redirects\Tables\RedirectsTable;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\PageUrl;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;

class RedirectResource extends Resource
{
    use HasConfiguredForm;
    use HasConfiguredTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ArrowUturnRight;

    protected static string $formConfigurator = RedirectForm::class;

    protected static string $tableConfigurator = RedirectsTable::class;

    protected static ?int $navigationSort = 9;

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return static::getFormConfigurator()::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return static::getTableConfigurator()::configure($table);
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->where('type', UrlTypeEnum::Redirect)
            ->with([
                'language',
                'site',
            ])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);

        return SiteScope::applyForCurrentActor($query);
    }

    /**
     * @return class-string<PageUrl>
     */
    #[Override]
    public static function getModel(): string
    {
        return PageUrl::class;
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return (string) __('capell-admin::navigation.group_system');
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return __('capell-admin::navigation.redirects');
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return __('capell-admin::navigation.redirects');
    }

    #[Override]
    public static function getModelLabel(): string
    {
        return __('capell-admin::generic.redirect');
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageRedirects::route('/'),
        ];
    }
}
