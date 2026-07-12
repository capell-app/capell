<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecPageData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Core\Support\Themes\ThemeInstallDefaultsRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class BuildCapellSiteFromSpecAction
{
    use AsObject;

    public function handle(CapellSiteSpecData $spec): Site
    {
        $hash = $this->canonicalHash($spec);
        $existing = Site::query()->where('meta->site_spec_hash', $hash)->first();

        if ($existing instanceof Site) {
            return $existing;
        }

        throw_if($spec->initialVisibility === 'public' && ! $spec->acknowledgePublic, RuntimeException::class, 'Public site specs require explicit acknowledgement.');

        return DB::transaction(function () use ($spec, $hash): Site {
            $language = $this->resolveLanguage($spec);
            $theme = $this->buildTheme($spec);

            resolve(ThemeInstallDefaultsRegistry::class)->install($spec->theme->key);

            $site = CreateSiteAction::run($spec->site->name, null, $language, collect([$language]), $theme);
            $site->meta = array_merge($site->meta ?? [], array_filter([
                'business_name' => $spec->site->businessName,
                'organization_type' => $spec->site->organisationType,
            ]), ['site_spec_hash' => $hash]);
            $site->save();

            $creator = resolve(PageCreator::class);

            foreach ($this->orderedPages($spec) as $index => $page) {
                $createdPage = $creator->createPage(
                    $this->pageData($page, $language, $index === 0, $spec->initialVisibility === 'public'),
                    $site,
                    collect([$language]),
                );
                SetupPageUrlsAction::run($createdPage);
            }

            return $site->refresh();
        });
    }

    private function resolveLanguage(CapellSiteSpecData $spec): Language
    {
        $language = Language::query()->where('code', $spec->language->code)->first()
            ?? CreateDefaultLanguagesAction::run([$spec->language->code])->first()
            ?? throw new RuntimeException(sprintf('Language [%s] could not be resolved.', $spec->language->code));

        $language->forceFill(Arr::only($spec->language->toArray(), ['name', 'locale', 'flag', 'default']))->save();

        return $language->refresh();
    }

    private function buildTheme(CapellSiteSpecData $spec): Theme
    {
        $theme = CreateThemeAction::run($spec->theme->key, ucfirst($spec->theme->key));
        $meta = is_array($theme->meta) ? $theme->meta : [];
        $colors = array_filter($spec->theme->colors->toArray(), filled(...));
        $theme->meta = array_merge($meta, $colors === [] ? [] : ['colors' => array_merge($meta['colors'] ?? [], $colors)], array_filter([
            'font_family' => $spec->theme->fontFamily,
            'link_color' => $spec->theme->linkColor,
            'link_color_active' => $spec->theme->linkColorActive,
            'container' => $spec->theme->container,
        ]));
        $theme->custom_css = $spec->theme->customCss ?: $theme->custom_css;
        $theme->save();

        return $theme->refresh();
    }

    /** @return list<CapellSiteSpecPageData> */
    private function orderedPages(CapellSiteSpecData $spec): array
    {
        return array_values(collect($spec->pages)->sortBy('order')->all());
    }

    /** @return array<string, mixed> */
    private function pageData(CapellSiteSpecPageData $page, Language $language, bool $first, bool $public): array
    {
        $content = collect($page->sections)->sortBy('order')->map(function ($section): string {
            $heading = filled($section->title) ? '<h2>' . e($section->title) . '</h2>' : '';

            return $heading . SanitizeSiteSpecSectionHtmlAction::run($section->content);
        })->implode('');

        if (! $public && $first) {
            $content = '<h1>Coming soon</h1><p>This site is being prepared.</p>';
        }

        return [
            'name' => $page->name,
            'type_key' => $page->pageType,
            'layout_key' => $first ? 'home' : 'default',
            'visible_from' => $public || $first ? now()->subDay()->toDateString() : null,
            'meta' => array_merge($page->meta, ['visibility' => $page->visibility, 'noindex' => ! $public]),
            'translations' => [$language->code => [
                'title' => ! $public && $first ? 'Coming soon' : $page->title,
                'content' => $content,
                'summary' => ! $public && $first ? 'This site is being prepared.' : $page->description,
                'slug' => ltrim($page->slug, '/'),
                'meta' => array_merge(['description' => $page->description, 'robots' => $public ? 'index,follow' : 'noindex,nofollow'], $page->meta),
            ]],
        ];
    }

    private function canonicalHash(CapellSiteSpecData $spec): string
    {
        $payload = $spec->toArray();
        $sort = function (array &$value) use (&$sort): void {
            foreach ($value as &$nested) {
                if (is_array($nested)) {
                    $sort($nested);
                }
            }

            unset($nested);
            if (! array_is_list($value)) {
                ksort($value);
            }
        };
        $sort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
