<?php

declare(strict_types=1);

namespace Capell\Core\Actions\SiteDomains;

use Capell\Core\Models\SiteDomain;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string run(SiteDomain $siteDomain, string $url)
 */
final class ResolveSiteDomainUrlAction
{
    use AsFake;
    use AsObject;

    public function handle(SiteDomain $siteDomain, string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (is_string($scheme) && $scheme !== '') {
            return $url;
        }

        return rtrim($siteDomain->full_url, '/') . '/' . ltrim($url, '/');
    }
}
