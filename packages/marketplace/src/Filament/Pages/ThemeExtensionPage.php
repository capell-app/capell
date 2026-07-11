<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Actions\Themes\CreateThemePreviewUrlAction;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Actions\CreateThemeAction;
use Capell\Core\Models\Page as PageModel;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ThemeManifestKey;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Marketplace\Actions\ApplyMarketplaceThemeToSitesAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Override;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ThemeExtensionPage extends Page
{
    use HasPageShield;

    public string $themeKey = '';

    public string $scope = 'all';

    public ?int $siteId = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?string $slug = 'extensions/themes/{themeKey}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'capell-marketplace::filament.pages.theme-extension';

    private ?CapellManifestData $resolvedManifest = null;

    #[Override]
    public static function canAccess(): bool
    {
        return ExtensionsPage::canManageExtensions();
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.theme_extension.navigation_label');
    }

    public function mount(string $themeKey): void
    {
        $this->themeKey = $themeKey;

        throw_unless($this->manifest() instanceof CapellManifestData, NotFoundHttpException::class);
    }

    #[Override]
    public function getTitle(): string
    {
        return __('capell-marketplace::marketplace.theme_extension.title', [
            'theme' => $this->themeName(),
        ]);
    }

    public function applyTheme(): void
    {
        $siteId = $this->selectedSiteIdForApply();
        $siteName = $siteId === null
            ? null
            : Site::query()->whereKey($siteId)->value('name');

        if ($this->isSiteScopedApply() && $siteId === null) {
            Notification::make()
                ->title(__('capell-marketplace::marketplace.theme_extension.site_required'))
                ->warning()
                ->send();

            return;
        }

        ApplyMarketplaceThemeToSitesAction::run(
            themeKey: $this->themeKey,
            themeName: $this->themeName(),
            siteId: $siteId,
        );

        $this->dispatch('$refresh');

        Notification::make()
            ->title($siteId === null
                ? __('capell-marketplace::marketplace.theme_extension.applied_all')
                : __('capell-marketplace::marketplace.theme_extension.applied_site'))
            ->body($siteId === null
                ? __('capell-marketplace::marketplace.theme_extension.applied_all_body', [
                    'theme' => $this->themeName(),
                ])
                : __('capell-marketplace::marketplace.theme_extension.applied_site_body', [
                    'theme' => $this->themeName(),
                    'site' => is_string($siteName) && $siteName !== '' ? $siteName : (string) $siteId,
                ]))
            ->success()
            ->send();
    }

    public function previewTheme(): ?RedirectResponse
    {
        $site = $this->previewSite();

        if (! $site instanceof Site) {
            Notification::make()
                ->title(__('capell-marketplace::marketplace.theme_extension.preview_site_required'))
                ->warning()
                ->send();

            return null;
        }

        $page = $this->previewPage($site);

        if (! $page instanceof PageModel) {
            Notification::make()
                ->title(__('capell-marketplace::marketplace.theme_extension.preview_page_required'))
                ->warning()
                ->send();

            return null;
        }

        return redirect()->away(CreateThemePreviewUrlAction::run(
            theme: $this->themeForPreview(),
            site: $site,
            page: $page,
            presetKey: $this->previewPresetKey(),
        ));
    }

    public function updatedScope(string $scope): void
    {
        if ($scope !== 'site') {
            $this->siteId = null;
        }
    }

    public function themeName(): string
    {
        return $this->manifest()?->displayName
            ?? str($this->themeKey)->replace(['-', '_'], ' ')->title()->toString();
    }

    public function theme(): ?Theme
    {
        return Theme::query()
            ->where('key', $this->themeKey)
            ->withCount('sites')
            ->first();
    }

    /**
     * @return Collection<int, Site>
     */
    public function sites(): Collection
    {
        return Site::query()
            ->with('theme')
            ->ordered()
            ->get();
    }

    public function manifest(): ?CapellManifestData
    {
        if ($this->resolvedManifest instanceof CapellManifestData) {
            return $this->resolvedManifest;
        }

        return $this->resolvedManifest = collect(resolve(CapellPackageRegistry::class)->all())
            ->first(fn (CapellManifestData $manifest): bool => $manifest->kind === 'theme'
                && ThemeManifestKey::resolve($manifest) === $this->themeKey);
    }

    private function isSiteScopedApply(): bool
    {
        return $this->scope === 'site' || $this->siteId !== null;
    }

    private function selectedSiteIdForApply(): ?int
    {
        if (! $this->isSiteScopedApply()) {
            return null;
        }

        return $this->siteId !== null
            && Site::query()->whereKey($this->siteId)->exists()
                ? $this->siteId
                : null;
    }

    private function previewSite(): ?Site
    {
        $selectedSiteId = $this->siteId;

        if ($selectedSiteId !== null) {
            $selectedSite = Site::query()->whereKey($selectedSiteId)->first();

            if ($selectedSite instanceof Site) {
                return $selectedSite;
            }
        }

        return Site::query()
            ->whereHas('pages')
            ->ordered()
            ->first()
            ?? Site::query()->ordered()->first();
    }

    private function previewPage(Site $site): ?PageModel
    {
        return PageModel::query()
            ->whereBelongsTo($site)
            ->homePage()
            ->first()
            ?? PageModel::query()
                ->whereBelongsTo($site)
                ->ordered()
                ->first();
    }

    private function themeForPreview(): Theme
    {
        return $this->theme() ?? CreateThemeAction::run(
            key: $this->themeKey,
            name: $this->themeName(),
            default: false,
            activePreset: $this->previewPresetKey(),
        );
    }

    private function previewPresetKey(): ?string
    {
        if (! app()->bound(ThemeRegistry::class)) {
            return null;
        }

        $registry = resolve(ThemeRegistry::class);

        if (! $registry->has($this->themeKey)) {
            return null;
        }

        return $registry->definition($this->themeKey)->presets[0]->key ?? null;
    }
}
