<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Creator\BlueprintCreator;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Site run(string $name, ?string $url, Language $language, ?Collection<int, Language> $languages = null, ?Theme $theme = null)
 */
class CreateSiteAction
{
    use AsObject;

    /**
     * @param  Collection<int, Language>|null  $languages
     */
    public function handle(string $name, ?string $url, Language $language, ?Collection $languages = null, ?Theme $theme = null): Site
    {
        /** @var class-string<Site> $siteModel */
        $siteModel = Site::class;

        /** @var Site|null $existing */
        $existing = $siteModel::query()->where('name', $name)->first();

        if ($existing !== null) {
            $this->ensureSiteLanguages($existing, $url, $language, $languages);

            return $existing;
        }

        $theme = $this->resolveTheme($theme);
        $type = $this->resolveSiteType();

        /** @var Site $site */
        $site = $siteModel::query()->create([
            'name' => $name,
            'default' => ! $siteModel::query()->exists(),
            'language_id' => $language->id,
            'blueprint_id' => $type->id,
            'theme_id' => $theme->id,
            'meta' => [
                'email' => config('mail.from.address'),
                'mail' => [
                    'use_site_logo' => true,
                ],
            ],
            'admin' => [
                'require_translations' => [$language->code],
            ],
        ]);

        $site->translations()->firstOrCreate([
            'language_id' => $language->id,
        ], [
            'title' => $name,
            'meta' => [
                'footer_copy' => sprintf('<p>&copy; :year %s</p>', $name),
            ],
        ]);

        $url ??= config('app.url');

        $urlParts = parse_url((string) $url);

        $urlParts = is_array($urlParts) ? $urlParts : [];

        $scheme = $urlParts['scheme'] ?? null;
        $domain = $urlParts['host'] ?? null;
        $path = isset($urlParts['path']) && $urlParts['path'] !== '' ? '/' . ltrim($urlParts['path'], '/') : null;

        $site->siteDomains()->firstOrCreate(
            [
                'domain' => $domain,
                'path' => $path,
                'scheme' => $scheme,
            ],
            [
                'language_id' => $language->id,
            ],
        );

        $languages?->each(function (Language $siteLanguage) use ($domain, $language, $path, $scheme, $site): void {
            if ($siteLanguage->id === $language->id) {
                return;
            }

            $site->translations()->firstOrCreate([
                'language_id' => $siteLanguage->id,
            ], [
                'title' => $site->name,
                'meta' => [
                    'footer_copy' => sprintf('<p>&copy; :year %s</p>', $site->name),
                ],
            ]);

            $site->siteDomains()->firstOrCreate(
                [
                    'language_id' => $siteLanguage->id,
                ],
                [
                    'domain' => $domain,
                    'scheme' => $scheme,
                    'path' => $siteLanguage->code . (in_array($path, [null, '', '0'], true) ? null : '/' . mb_ltrim($path, '/')),
                ],
            );
        });

        return $site;
    }

    /**
     * @param  Collection<int, Language>|null  $languages
     */
    private function ensureSiteLanguages(Site $site, ?string $url, Language $language, ?Collection $languages = null): void
    {
        $languages ??= collect([$language]);
        $url ??= config('app.url');

        $urlParts = parse_url((string) $url);
        $urlParts = is_array($urlParts) ? $urlParts : [];

        $scheme = $urlParts['scheme'] ?? null;
        $domain = $urlParts['host'] ?? null;
        $path = isset($urlParts['path']) && $urlParts['path'] !== '' ? '/' . ltrim($urlParts['path'], '/') : null;

        $languages->each(function (Language $siteLanguage) use ($domain, $language, $path, $scheme, $site): void {
            $site->translations()->firstOrCreate([
                'language_id' => $siteLanguage->id,
            ], [
                'title' => $site->name,
                'meta' => [
                    'footer_copy' => sprintf('<p>&copy; :year %s</p>', $site->name),
                ],
            ]);

            $site->siteDomains()->firstOrCreate(
                [
                    'language_id' => $siteLanguage->id,
                ],
                [
                    'domain' => $domain,
                    'scheme' => $scheme,
                    'path' => $siteLanguage->id === $language->id
                        ? $path
                        : $siteLanguage->code . (in_array($path, [null, '', '0'], true) ? null : '/' . mb_ltrim($path, '/')),
                ],
            );
        });
    }

    private function resolveTheme(?Theme $theme): Theme
    {
        if ($theme instanceof Theme) {
            return $theme;
        }

        $existing = Theme::query()->default()->first();

        if ($existing !== null) {
            return $existing;
        }

        return CreateThemeAction::run(defaultColors: true);
    }

    private function resolveSiteType(): Blueprint
    {
        /** @var class-string<Blueprint> $typeModel */
        $typeModel = Blueprint::class;

        /** @var Blueprint|null $type */
        $type = $typeModel::query()->siteType()->default()->first();

        if ($type !== null) {
            return $type;
        }

        return resolve(BlueprintCreator::class)->createSiteType();
    }
}
