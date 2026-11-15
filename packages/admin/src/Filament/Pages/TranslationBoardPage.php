<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Actions\Translations\BuildTranslationBoardRowsAction;
use Capell\Admin\Data\Translations\TranslationBoardLanguageData;
use Capell\Admin\Enums\TranslationBoardStatus;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page as PageModel;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Override;

/** Pages down the side, languages across the top: where each page stands in each language of one site. */
final class TranslationBoardPage extends Page
{
    use HasPageShield;
    use WithPagination;

    private const int PAGES_PER_SCREEN = 50;

    #[Url]
    public ?int $site_id = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Language;

    protected static ?string $slug = 'translations';

    protected static ?int $navigationSort = -70;

    protected string $view = 'capell-admin::filament.pages.translation-board';

    /** @var Collection<int, Site>|null */
    private ?Collection $sites = null;

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::translation_board.navigation_label');
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return (string) __('capell-admin::navigation.group_websites');
    }

    #[Override]
    public function getTitle(): string
    {
        return (string) __('capell-admin::translation_board.title');
    }

    #[Override]
    public function getSubheading(): string
    {
        return (string) __('capell-admin::translation_board.subheading');
    }

    public function mount(): void
    {
        $this->site_id = $this->selectedSite()?->getKey();
    }

    public function updatedSiteId(): void
    {
        $this->site_id = $this->selectedSite()?->getKey();
        $this->resetPage();
    }

    #[Override]
    protected function getViewData(): array
    {
        $site = $this->selectedSite();
        $languages = $site instanceof Site ? $this->boardLanguages($site) : [];
        $pages = $site instanceof Site && count($languages) > 1 ? $this->pages($site, $languages) : null;

        return [
            'sites' => $this->sites()->mapWithKeys(fn (Site $site): array => [(int) $site->getKey() => (string) $site->name])->all(),
            'languages' => $languages,
            'pages' => $pages,
            'rows' => $pages instanceof LengthAwarePaginator ? BuildTranslationBoardRowsAction::run($pages->items(), $languages, PageResource::canAccess()) : [],
            'statuses' => TranslationBoardStatus::cases(),
        ];
    }

    /**
     * @param  list<TranslationBoardLanguageData>  $languages
     * @return LengthAwarePaginator<int, PageModel>
     */
    private function pages(Site $site, array $languages): LengthAwarePaginator
    {
        $languageIds = array_map(static fn (TranslationBoardLanguageData $language): int => $language->id, $languages);

        return SiteAccess::current()->query(PageModel::class)
            ->where('site_id', $site->getKey())
            ->with([
                // The page editor URL reads the blueprint.
                'blueprint',
                'translations' => fn ($query) => $query->whereIn('language_id', $languageIds),
            ])
            ->orderBy('id')
            ->paginate(self::PAGES_PER_SCREEN);
    }

    /** @return list<TranslationBoardLanguageData> */
    private function boardLanguages(Site $site): array
    {
        /** @var Builder<Language> $query */
        $query = $site->languages()->getQuery();

        return array_values($query->ordered()
            ->get()
            ->unique('id')
            ->map(static fn (Language $language): TranslationBoardLanguageData => new TranslationBoardLanguageData(
                id: (int) $language->getKey(),
                name: (string) $language->name,
                isDefault: (bool) $language->getAttribute('default'),
            ))
            ->all());
    }

    private function selectedSite(): ?Site
    {
        $sites = $this->sites();

        return $sites->firstWhere('id', $this->site_id)
            ?? $sites->firstWhere('default', true)
            ?? $sites->first();
    }

    /** @return Collection<int, Site> */
    private function sites(): Collection
    {
        return $this->sites ??= SiteAccess::current()->query(Site::class)->ordered()->get();
    }
}
