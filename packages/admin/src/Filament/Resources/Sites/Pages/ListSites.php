<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites\Pages;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\Site\CreateSiteAction;
use Capell\Admin\Filament\Concerns\ApplySearchRelationsTable;
use Capell\Admin\Filament\Concerns\HasImportExportHeaderActions;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Sites\Widgets\ListSiteAlertsWidget;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Override;

class ListSites extends ListRecords
{
    use ApplySearchRelationsTable;
    use HasImportExportHeaderActions;

    /** @return class-string<SiteResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<SiteResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Site);

        return $resource;
    }

    /** @return Builder<Site> */
    public function getFilteredTableQuery(): Builder
    {
        $query = parent::getFilteredTableQuery();

        if (! $query instanceof Builder) {
            $query = Site::query();
        }

        if (isset($this->getTableFilterState('filter')['language_id'])) {
            $language_id = $this->getTableFilterState('filter')['language_id'];
        } else {
            /** @var class-string<Language> $model */
            $model = Language::class;

            $language_id = $model::query()->default()->value('id');
        }

        $query->with([
            'translation' => fn (BuilderContract $query): BuilderContract => $query->where('language_id', (int) $language_id),
            'siteDomain' => fn (BuilderContract $query): BuilderContract => $query->where('language_id', (int) $language_id),
        ]);

        return $query;
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::generic.sites_info');
    }

    /** @return array<int, mixed> */
    #[Override]
    protected function getHeaderWidgets(): array
    {
        return [
            ListSiteAlertsWidget::class,
        ];
    }

    /** @return array<int, mixed> */
    #[Override]
    protected function getActions(): array
    {
        return $this->prependImportHeaderAction([
            CreateSiteAction::make()->redirectAfterCreate(),
        ]);
    }

    /** @return array<string, array<int|string, string>> */
    protected function getSearchRelationColumns(): array
    {
        /** @var array<string, array<int|string, string>> $columns */
        $columns = [
            'translations' => [
                'meta->label',
                'title',
                'content',
            ],
            'siteDomains' => [
                'domain',
                'path',
                DB::raw("RTRIM((scheme || '://' || domain || COALESCE(path, '')), '/')"),
            ],
        ];

        return $columns;
    }

    /**
     * @param  Builder<Site>  $query
     * @return Paginator<int, Site>
     */
    protected function paginateTableQuery(Builder $query): Paginator
    {
        $recordsPerPage = $this->getTableRecordsPerPage();
        $perPage = $recordsPerPage === -1 ? $query->count() : (int) $recordsPerPage;

        /** @var LengthAwarePaginator<int, Site> $records */
        $records = $query->paginate(
            $perPage,
            ['id'],
            $this->getTablePaginationPageName(),
        );

        return $records->onEachSide(1);
    }
}
