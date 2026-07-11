<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Data\RedirectDecisionData;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;

interface RedirectResolver
{
    public function resolve(Site $site, Language $language, string $url, ?int $pageId = null, ?PageUrl $pageUrl = null): ?RedirectDecisionData;
}
