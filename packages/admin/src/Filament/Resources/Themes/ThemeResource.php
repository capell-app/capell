<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Themes;

use BackedEnum;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Concerns\HasConfiguredForm;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Filament\Concerns\HasNavigationBadge;
use Capell\Admin\Filament\Resources\Themes\Pages\ManageThemes;
use Capell\Admin\Filament\Resources\Themes\Schemas\ThemeForm;
use Capell\Admin\Filament\Resources\Themes\Tables\ThemesTable;
use Capell\Core\Models\Theme;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;

class ThemeResource extends Resource
{
    use HasConfiguredForm;
    use HasConfiguredTable;
    use HasNavigationBadge;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Swatch;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string $formConfigurator = ThemeForm::class;

    protected static string $tableConfigurator = ThemesTable::class;

    protected static ?int $navigationSort = 8;

    #[Override]
    public static function table(Table $table): Table
    {
        return static::configuredTable($table, ConfiguratorTypeEnum::Theme)
            ->extraAttributes(['data-tour-id' => 'welcome-tour-themes']);
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'creator',
                'editor',
                'media',
            ])
            ->withCount('sites')
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * @return class-string<Theme>
     */
    #[Override]
    public static function getModel(): string
    {
        return Theme::class;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::navigation.themes');
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return (string) __('capell-admin::navigation.group_websites');
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageThemes::route('/'),
        ];
    }
}
