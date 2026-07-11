<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Actions\Diagnostics\BuildSiteHealthReportAction;
use Capell\Admin\Contracts\Diagnostics\SiteHealthWidget;
use Capell\Admin\Contracts\Diagnostics\SiteHealthWidgetWithParameters;
use Capell\Admin\Data\Diagnostics\SiteHealthReportData;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Override;

final class SiteHealthPage extends Page
{
    use HasPageShield;

    public int|string|null $selectedSiteId = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Heart;

    protected static ?string $slug = 'site-health';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 10;

    protected string $view = 'capell-admin::filament.pages.site-health';

    /** @var array<int, string>|null */
    private ?array $siteOptionsCache = null;

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::navigation.site_health');
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return (string) __('capell-admin::navigation.group_system');
    }

    public function mount(): void
    {
        $this->selectedSiteId = $this->normalisedSelectedSiteId();
    }

    public function updatedSelectedSiteId(): void
    {
        $this->selectedSiteId = $this->normalisedSelectedSiteId();
    }

    #[Override]
    public function getTitle(): string
    {
        return __('capell-admin::generic.site_health');
    }

    #[Override]
    public function getSubheading(): string
    {
        return __('capell-admin::generic.site_health_info');
    }

    public function getReport(): SiteHealthReportData
    {
        return BuildSiteHealthReportAction::run($this->normalisedSelectedSiteId());
    }

    /**
     * @return array<int, string>
     */
    public function siteOptions(): array
    {
        if ($this->siteOptionsCache !== null) {
            return $this->siteOptionsCache;
        }

        /** @var Builder<Site> $query */
        $query = SiteScope::applyForCurrentActor(Site::query(), 'id', denyWhenMissingActor: true);

        $this->siteOptionsCache = $query
            ->with('siteDomains')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Site $site): array => [(int) $site->getKey() => $this->siteOptionLabel($site)])
            ->all();

        return $this->siteOptionsCache;
    }

    /**
     * @return list<SiteHealthWidget>
     */
    public function siteHealthWidgets(): array
    {
        return array_values(collect(app()->tagged(SiteHealthWidget::TAG))
            ->filter(fn (mixed $widget): bool => $widget instanceof SiteHealthWidget)
            ->values()
            ->all());
    }

    public function normalisedSelectedSiteId(): ?int
    {
        $siteOptions = $this->siteOptions();
        $selectedSiteId = is_numeric($this->selectedSiteId) ? (int) $this->selectedSiteId : null;

        if ($selectedSiteId !== null && array_key_exists($selectedSiteId, $siteOptions)) {
            return $selectedSiteId;
        }

        $firstSiteId = array_key_first($siteOptions);

        return is_int($firstSiteId) ? $firstSiteId : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function siteHealthWidgetParameters(SiteHealthWidget $widget): array
    {
        return $widget instanceof SiteHealthWidgetWithParameters
            ? $widget->parameters($this->normalisedSelectedSiteId())
            : [];
    }

    private function siteOptionLabel(Site $site): string
    {
        $domain = $site->siteDomains->firstWhere('default', true) ?? $site->siteDomains->first();

        if ($domain === null || $domain->domain === null || $domain->domain === '') {
            return $site->name;
        }

        $path = is_string($domain->path) && $domain->path !== '' && $domain->path !== '/'
            ? $domain->path
            : '';

        return sprintf('%s (%s://%s%s)', $site->name, $domain->scheme ?? 'https', $domain->domain, $path);
    }
}
