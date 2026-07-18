<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class PurgeCdnCacheByPageAction
{
    use AsFake;
    use AsObject;

    /**
     * Purge CDN cache for a page via surrogate keys (Cloudflare, Fastly, Varnish, etc.).
     *
     * Dispatches a queued job to POST to the CDN provider's purge API.
     * Also invalidates locally cached fragments associated with this page.
     * Supports Cloudflare Purge Cache API, Fastly Soft Purge, Varnish BAN, etc.
     */
    public function handle(Page $page): void
    {
        $surrogateKeys = $this->buildSurrogateKeys($page);

        if ($surrogateKeys === []) {
            return;
        }

        InvalidateFrontendSurrogateKeysAction::run($surrogateKeys);
    }

    /**
     * @return array<int, string>
     */
    private function buildSurrogateKeys(Page $page): array
    {
        $keys = [];

        // Page-specific key
        $keys[] = 'page-' . $page->getKey();

        // Site key (affects all pages on site)
        if ($page->site_id !== null) {
            $keys[] = 'site-' . $page->site_id;
        }

        // Language-specific keys (one per linked language)
        $page->loadMissing('languages');
        foreach ($page->languages as $language) {
            $keys[] = 'lang-' . $language->code;
        }

        return array_values(array_unique($keys));
    }
}
