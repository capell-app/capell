<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\PageContext;

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;

class ActionPageContextResource extends PageResource
{
    public static function getBasePath(Site $site, Language $language): string
    {
        return '/action/';
    }
}
