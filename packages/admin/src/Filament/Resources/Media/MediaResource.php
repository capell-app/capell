<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Media;

use BackedEnum;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Filament\Concerns\HasNavigationBadge;
use Capell\Admin\Filament\Resources\Media\Pages\EditMedia;
use Capell\Admin\Filament\Resources\Media\Pages\ListMedia;
use Capell\Admin\Filament\Resources\Media\Tables\MediaTable;
use Capell\Admin\Support\MediaScope;
use Capell\Core\Models\Media;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

class MediaResource extends Resource
{
    use HasConfiguredTable;
    use HasNavigationBadge;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Photo;

    protected static ?string $recordTitleAttribute = 'file_name';

    protected static ?int $navigationSort = 4;

    /** @var class-string<MediaTable> */
    protected static string $tableConfigurator = MediaTable::class;

    #[Override]
    public static function table(Table $table): Table
    {
        return static::getTableConfigurator()::configure($table)
            ->extraAttributes(['data-tour-id' => 'welcome-tour-media']);
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return MediaScope::applyForCurrentActor(parent::getEloquentQuery())
            ->with(['model']);
    }

    /**
     * @return class-string<SpatieMedia>
     */
    #[Override]
    public static function getModel(): string
    {
        return Media::class;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::navigation.media');
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return (string) __('capell-admin::navigation.group_content');
    }

    #[Override]
    public static function getNavigationParentItem(): ?string
    {
        return null;
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),
            'edit' => EditMedia::route('/{record}/edit'),
        ];
    }
}
