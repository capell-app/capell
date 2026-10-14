<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Capell\Core\Contracts\RedirectResolver;
use Capell\Core\Data\RedirectDecisionData;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\Redirects\PageUrlRedirectResolver;
use Override;

final class LegacyDecisionRedirectResolverForMountedRoute implements RedirectResolver
{
    public function __construct(private readonly PageUrlRedirectResolver $resolver) {}

    #[Override]
    public function resolve(Site $site, Language $language, string $url, ?int $pageId = null, ?PageUrl $pageUrl = null): ?RedirectDecisionData
    {
        $decision = $this->resolver->resolve($site, $language, $url, $pageId, $pageUrl);

        return $decision instanceof RedirectDecisionData ? new RedirectDecisionData($decision->targetUrl, $decision->statusCode) : null;
    }
}
