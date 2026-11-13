<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\FirstRun;

use Capell\Admin\Contracts\FirstRun\GuidedSetupPreviewNavigationRenderer;
use Capell\Core\Actions\FirstRun\BuildGuidedSetupSpecAction;
use Capell\Core\Actions\SanitizeSiteSpecSectionHtmlAction;
use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecPageData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecSectionData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Assets\ThemeTokenRenderer;
use Capell\Core\ThemeStudio\Assets\ThemeTokenValidator;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Support\Facades\View;
use Illuminate\View\Component;
use Illuminate\View\Factory;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/** Renders draft content with the selected theme's styles without applying a spec or publishing token files. */
final class BuildGuidedSetupPreviewAction
{
    use AsObject;

    public function handle(GuidedSetupInputData $input, Site $site): string
    {
        $spec = resolve(BuildGuidedSetupSpecAction::class)->handle($input, $site);
        $originalViews = resolve(\Illuminate\Contracts\View\Factory::class);
        $engines = clone $originalViews->getEngineResolver();
        foreach (array_unique($originalViews->getExtensions()) as $engineName) {
            $engine = clone $engines->resolve($engineName);
            $engines->register($engineName, static fn (): object => $engine);
        }

        $previewViews = new Factory($engines, clone $originalViews->getFinder(), $originalViews->getDispatcher());
        $previewViews->setContainer($originalViews->getContainer());
        foreach (array_reverse($originalViews->getExtensions(), true) as $extension => $engineName) {
            $previewViews->addExtension($extension, $engineName);
        }

        $previewViews->share([...$originalViews->getShared(), '__env' => $previewViews]);

        // A failed nested Blade render flushes its factory's component/slot stacks.
        // Keep optional theme failures away from the wizard currently rendering.
        View::swap($previewViews);
        Component::forgetFactory();
        try {
            return $this->render($spec, $input, $site);
        } finally {
            View::swap($originalViews);
            Component::forgetFactory();
        }
    }

    private function render(CapellSiteSpecData $spec, GuidedSetupInputData $input, Site $site): string
    {
        $theme = Theme::query()->where('key', $spec->theme->key)->first();
        if (! $theme instanceof Theme) {
            $siteTheme = $site->theme;
            $theme = $siteTheme instanceof Theme ? $siteTheme : null;
        }

        $registry = resolve(ThemeRegistry::class);
        $definition = $theme instanceof Theme && $registry->has($theme->key) ? $registry->definition($theme->key) : null;
        $settings = app()->bound(ThemeRuntimeSettings::class) ? resolve(ThemeRuntimeSettings::class) : null;
        $brand = $settings?->brandProfile() ?? new BrandProfileData;
        $presetKey = data_get($theme?->meta, 'editor.preset.active', $settings?->activePreset());
        $preset = is_string($presetKey) ? $definition?->preset($presetKey) : null;
        $preset ??= $definition->presets[0] ?? null;
        $brand = $brand->merge($preset->values ?? []);
        $brand = $brand->merge($settings?->themeOverrides()[$theme->key ?? ''] ?? []);

        $savedTokens = $theme instanceof Theme ? data_get($theme->meta, 'editor.tokens', []) : [];
        $brand = $brand->merge(is_array($savedTokens) ? $savedTokens : []);

        if ($input->primaryColour !== null) {
            $brand = $brand->merge(['primaryColor' => $input->primaryColour]);
        }

        $packagePath = $definition !== null && CapellCore::hasPackage($definition->package) ? CapellCore::getPackage($definition->package)->path : null;
        $views = is_string($packagePath) ? rtrim($packagePath, '/') . '/resources/views/' : null;
        $headerData = [
            'name' => $spec->site->name,
            'tagline' => $input->tagline,
            'pages' => $spec->pages,
            'navigations' => $spec->navigations,
        ];
        $header = view('capell-admin::filament.pages.guided-setup-preview-header', [
            ...$headerData,
            'nativeNavigation' => $this->navigation($definition, $views, $spec, $input),
        ])->render();

        $content = view('capell-admin::filament.pages.guided-setup-preview-content', [
            'pages' => array_map(static fn (CapellSiteSpecPageData $page): array => [
                'slug' => $page->slug,
                'name' => $page->name,
                'title' => $page->title,
                'sections' => array_map(static fn (CapellSiteSpecSectionData $section): array => [
                    'title' => $section->title,
                    'html' => SanitizeSiteSpecSectionHtmlAction::run($section->content),
                ], $page->sections),
            ], $spec->pages),
        ])->render();

        $nativeContent = $this->page($views, $brand, $header, $content);

        return view('capell-admin::filament.pages.guided-setup-preview', [
            'name' => $spec->site->name,
            'content' => $nativeContent ?? $header . $content,
            'nativePage' => $nativeContent !== null,
            'stylesheet' => $this->stylesheet($definition),
            'tokens' => (new ThemeTokenRenderer)->css($brand),
            'locale' => $spec->language->code,
        ])->render();
    }

    private function navigation(?ThemeDefinitionData $definition, ?string $views, CapellSiteSpecData $spec, GuidedSetupInputData $input): ?string
    {
        // Navigation hydration belongs to the theme: its public DTO contract may
        // use array links, typed objects, or additional required context.
        $rendererClass = $definition?->frontend['setupPreviewNavigationRenderer'] ?? null;
        if ($views === null || $spec->navigations === [] || ! is_string($rendererClass)
            || ! is_a($rendererClass, GuidedSetupPreviewNavigationRenderer::class, true)) {
            return null;
        }

        foreach (['sections/navigation.blade.php', 'theme/sections/navigation.blade.php'] as $relativePath) {
            if (is_file($views . $relativePath)) {
                try {
                    return resolve($rendererClass)->render($views . $relativePath, $spec, $input);
                } catch (Throwable) {
                    // Unsupported native contracts must never prevent setup.
                    return null;
                }
            }
        }

        return null;
    }

    private function page(?string $views, BrandProfileData $brand, string $header, string $content): ?string
    {
        if ($views === null) {
            return null;
        }

        // Use the public page contract, including font properties and tokens().
        // Reject unsafe source values before native shells inline their tokens.
        $validator = new ThemeTokenValidator;
        $publicBrand = (new BrandProfileData)->merge(array_filter(
            $brand->toArray(),
            static fn (mixed $value): bool => is_string($value) && $validator->safeCssValue($value),
        ))->withCustomTokens(array_filter(
            $brand->customTokens,
            $validator->safeCssValue(...),
        ));
        foreach (['page.blade.php', 'theme/page.blade.php'] as $relativePath) {
            if (is_file($views . $relativePath)) {
                try {
                    return view()->file($views . $relativePath, [
                        'brand' => $publicBrand,
                        'content' => $content,
                        'chromeHeader' => $header,
                        'chromeFooter' => null,
                        'mainContent' => $content,
                    ])->render();
                } catch (Throwable) {
                    // Some themes need a full page context unavailable to unsaved input.
                    return null;
                }
            }
        }

        return null;
    }

    private function stylesheet(?ThemeDefinitionData $definition): ?string
    {
        // Only a published local stylesheet is loaded; scripts and editor routes have no role here.
        $stylesheet = $definition->assets['css'] ?? null;

        return is_string($stylesheet) && preg_match('~\A(?:vendor|build)/[a-zA-Z0-9_./-]+\.css\z~', $stylesheet) === 1
            && ! str_contains($stylesheet, '..') ? asset($stylesheet) : null;
    }
}
