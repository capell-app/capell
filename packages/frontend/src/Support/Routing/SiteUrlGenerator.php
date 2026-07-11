<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Routing;

use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Routing\UrlGenerator as BaseUrlGenerator;
use Override;

class SiteUrlGenerator extends BaseUrlGenerator
{
    /**
     * @param  string  $path
     * @param  mixed  $extra
     * @param  bool|null  $secure
     */
    #[Override]
    public function to($path, $extra = [], $secure = null): string
    {
        $url = parent::to($path, $extra, $secure);

        if (config('capell-frontend.use_site_domain_for_urls', false) === false) {
            return $url;
        }

        $base = resolve(FrontendState::class)->baseUrl();

        if (! is_string($base) || $base === '') {
            return $url;
        }

        return $this->rewriteOrigin($url, $base);
    }

    private function rewriteOrigin(string $url, string $base): string
    {
        $parsed = parse_url($url);
        $base = rtrim($base, '/');
        $path = is_array($parsed) && isset($parsed['path']) ? $parsed['path'] : '';
        $query = is_array($parsed) && isset($parsed['query']) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '';

        // Ensure only one slash between base and path
        if ($path !== '' && $path[0] !== '/') {
            $path = '/' . $path;
        }

        // If base is just scheme+host (no path), $base will not end with a slash, so '/foo' is fine
        // If base has a path, e.g. https://foo.com/bar, we want https://foo.com/bar/foo
        // rtrim($base, '/') ensures no trailing slash
        // $path always starts with '/'
        // So https://foo.com/bar + /foo => https://foo.com/bar/foo

        return $base . $path . $query;
    }
}
