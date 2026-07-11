<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Themes;

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Symfony\Component\HttpFoundation\Response;

interface ThemePreviewRendererInterface
{
    public function render(
        Theme $theme,
        Site $site,
        Page $page,
        ?Language $language = null,
        ?SiteDomain $siteDomain = null,
    ): Response;
}
