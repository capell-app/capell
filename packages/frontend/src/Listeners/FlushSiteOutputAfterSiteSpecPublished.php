<?php

declare(strict_types=1);

namespace Capell\Frontend\Listeners;

use Capell\Core\Events\SiteSpecPublished;
use Capell\Frontend\Actions\FlushSitePublicOutputAction;

final class FlushSiteOutputAfterSiteSpecPublished
{
    public function handle(SiteSpecPublished $event): void
    {
        FlushSitePublicOutputAction::run($event->site);
    }
}
