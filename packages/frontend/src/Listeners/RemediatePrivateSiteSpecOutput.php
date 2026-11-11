<?php

declare(strict_types=1);

namespace Capell\Frontend\Listeners;

use Capell\Core\Data\SiteSpec\SiteSpecPrivacyRepairData;
use Capell\Core\Events\SiteSpecPrivacyRepaired;
use Capell\Core\Events\SiteSpecPrivacyRepairing;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Actions\FlushSitePublicOutputAction;

final class RemediatePrivateSiteSpecOutput
{
    public function handle(SiteSpecPrivacyRepairing $event): void
    {
        FlushSitePublicOutputAction::run($event->site);

        // Dispatch inline: a package failure must propagate to the importer
        // before it records completion, regardless of queue configuration.
        event(new SiteSpecPrivacyRepaired(new SiteSpecPrivacyRepairData(
            siteIds: [$event->site->id],
            domains: $event->site->siteDomains()->get()
                ->map(static fn (SiteDomain $domain): ?string => $domain->domain)
                ->filter(static fn (?string $domain): bool => $domain !== null && $domain !== '')
                ->unique()->values()->all(),
        )));
    }
}
