<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Loader;

use Capell\Core\Contracts\RedirectResolver as CoreRedirectResolver;
use Capell\Core\Data\RedirectDecisionData;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\RedirectResolver;
use Override;

final readonly class NullRedirectResolver implements RedirectResolver
{
    public function __construct(
        private CoreRedirectResolver $redirectResolver,
    ) {}

    #[Override]
    public function resolve(Site $site, Language $language, string $url, ?int $pageId = null, ?PageUrl $pageUrl = null): ?RedirectDecisionData
    {
        return $this->redirectResolver->resolve($site, $language, $url, $pageId, $pageUrl);
    }
}
