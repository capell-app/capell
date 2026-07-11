<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Languages;

use BackedEnum;
use Capell\Admin\Filament\Concerns\HasConfiguredForm;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Filament\Concerns\HasNavigationBadge;
use Capell\Admin\Filament\Resources\Languages\Pages\ManageLanguages;
use Capell\Admin\Filament\Resources\Languages\Schemas\LanguageForm;
use Capell\Admin\Filament\Resources\Languages\Tables\LanguagesTable;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Language;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;

class LanguageResource extends Resource
{
    use HasConfiguredForm;
    use HasConfiguredTable;
    use HasNavigationBadge;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ChatBubbleLeftRight;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string $formConfigurator = LanguageForm::class;

    protected static string $tableConfigurator = LanguagesTable::class;

    protected static ?int $navigationSort = 7;

    /**
     * @return class-string<Language>
     */
    #[Override]
    public static function getModel(): string
    {
        return Language::class;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) (__('capell-admin::navigation.languages'));
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return (string) __('capell-admin::navigation.group_system');
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'creator',
                'editor',
            ])
            ->withCount([
                'sites' => fn (Builder $query): Builder => SiteScope::applyForCurrentActor($query, 'sites.id'),
            ])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageLanguages::route('/'),
        ];
    }
}
