<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Support\Extensions\Fixtures;

use Capell\Admin\Contracts\Diagnostics\SiteHealthSubpage;
use Filament\Pages\Page;

final class BuilderSiteHealthExtensionPage extends Page implements SiteHealthSubpage
{
    protected static bool $shouldRegisterNavigation = true;
}
