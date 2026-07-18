<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Events\SiteCreated;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(Site $site, array<string, mixed> $formData)
 */
class SiteCreatedAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $formData
     */
    public function handle(Site $site, array $formData): void
    {
        $languages = $this->resolveLanguages($formData);

        $this->createDomains($site, $formData);
        $this->createTranslations($site, $languages, $formData);
        $this->createDefaultPages($site, $languages, $formData);

        event(new SiteCreated($site, $formData));
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function createDomains(Site $site, array $formData): void
    {
        if ($site->siteDomains()->exists()) {
            return;
        }

        $domains = $formData['site_domains'] ?? null;

        if (! is_array($domains) || $domains === []) {
            return;
        }

        foreach ($domains as $domain) {
            if (! is_array($domain)) {
                continue;
            }

            if (! isset($domain['url'])) {
                continue;
            }

            $urlParts = parse_url((string) $domain['url']);

            if ($urlParts === false) {
                continue;
            }

            SiteDomain::query()->create([
                'site_id' => $site->getKey(),
                'language_id' => $domain['language_id'] ?? $site->language_id,
                'scheme' => $urlParts['scheme'] ?? null,
                'domain' => ($domain['use_host_domain'] ?? false) === true ? null : ($urlParts['host'] ?? null),
                'path' => isset($urlParts['path']) && ! in_array(mb_rtrim($urlParts['path'], '/'), ['', '0'], true)
                    ? mb_rtrim($urlParts['path'], '/')
                    : null,
                'default' => (bool) ($domain['default'] ?? false),
                'status' => (bool) ($domain['status'] ?? true),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return Collection<int, Language>
     */
    private function resolveLanguages(array $formData): Collection
    {
        $ids = [$formData['language_id'] ?? null];

        if (isset($formData['languages']) && is_array($formData['languages'])) {
            $ids = array_merge($ids, $formData['languages']);
        }

        $ids = array_filter(array_unique($ids), static fn (mixed $id): bool => $id !== null);

        /** @var class-string<Language> $model */
        $model = Language::class;

        return $model::query()->whereIn('id', $ids)->get();
    }

    /**
     * @param  Collection<int, Language>  $languages
     * @param  array<string, mixed>  $formData
     */
    private function createTranslations(Site $site, Collection $languages, array $formData): void
    {
        $languages->each(function (Language $language) use ($site, $formData): void {
            $site->translations()->createOrFirst([
                'language_id' => $language->id,
            ], [
                'title' => $formData['name'] ?? $site->name,
            ]);
        });
    }

    /**
     * @param  Collection<int, Language>  $languages
     * @param  array<string, mixed>  $formData
     */
    private function createDefaultPages(Site $site, Collection $languages, array $formData): void
    {
        $autoCreatePages = $formData['auto_create_pages'] ?? null;

        if (! is_array($autoCreatePages) || $autoCreatePages === []) {
            return;
        }

        if (! app()->bound('capell.admin.create-default-pages-action')) {
            return;
        }

        app()->call(resolve('capell.admin.create-default-pages-action'), [
            'site' => $site,
            'languages' => $languages,
            'pages' => $autoCreatePages,
        ]);
    }
}
