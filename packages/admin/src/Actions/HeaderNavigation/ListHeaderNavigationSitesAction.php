<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\HeaderNavigation;

use Capell\Admin\Data\HeaderNavigation\HeaderNavigationSiteData;
use Capell\Admin\Support\HeaderNavigation\HeaderNavigationAccessResolver;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ListHeaderNavigationSitesAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly HeaderNavigationAccessResolver $accessResolver,
    ) {}

    /**
     * @return list<HeaderNavigationSiteData>
     */
    public function handle(?Authenticatable $actor): array
    {
        if (! $actor instanceof Authenticatable) {
            return [];
        }

        return array_values($this->accessResolver
            ->visibleSites($actor)
            ->map(fn (Site $site): HeaderNavigationSiteData => new HeaderNavigationSiteData(
                id: (int) $site->getKey(),
                name: (string) $site->name,
                editUrl: $this->accessResolver->siteEditUrlFor($site),
                publicUrl: $this->publicUrlFor($site),
            ))
            ->all());
    }

    private function publicUrlFor(Site $site): ?string
    {
        $domain = $site->defaultDomain;

        if (! $domain instanceof SiteDomain) {
            return null;
        }

        return $domain->full_url;
    }
}
