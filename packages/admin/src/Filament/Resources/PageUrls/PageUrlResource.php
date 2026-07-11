<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\PageUrls;

use BackedEnum;
use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Filament\Concerns\HasConfiguredForm;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Filament\Resources\PageUrls\Pages\ManagePageUrls;
use Capell\Admin\Filament\Resources\PageUrls\Schemas\PageUrlForm;
use Capell\Admin\Filament\Resources\PageUrls\Tables\PageUrlsTable;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\PageUrl;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;

class PageUrlResource extends Resource
{
    use HasConfiguredForm;
    use HasConfiguredTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Link;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $formConfigurator = PageUrlForm::class;

    protected static string $tableConfigurator = PageUrlsTable::class;

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        $relations = [
            'pageable' => fn (mixed $query): mixed => $query instanceof Builder ? self::applyPageTableExtenders($query) : $query,
            'siteDomain',
        ];

        $query = parent::getEloquentQuery()
            ->with($relations)
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
    public static function getPages(): array
    {
        return [
            'index' => ManagePageUrls::route('/'),
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function applyPageTableExtenders(Builder $query): Builder
    {
        foreach (app()->tagged(PageTableExtender::TAG) as $extender) {
            if ($extender instanceof PageTableExtender) {
                $query = $extender->modifyQuery($query);
            }
        }

        return $query;
    }
}
