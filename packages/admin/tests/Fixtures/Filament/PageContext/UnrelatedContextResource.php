<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\PageContext;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;

class UnrelatedContextResource
{
    public static function getBasePath(Site $site, Language $language): string
    {
        return '/unrelated/';
    }
}
