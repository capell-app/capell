<?php

declare(strict_types=1);

namespace Capell\Core\Support\Upgrade;

use Capell\Core\Actions\FirstRun\ReconcileSitePublicationMilestonesAction;
use Capell\Core\Data\UpgradeContext;
use Override;

/**
 * Records sites that were already live before Capell tracked when a site goes
 * live, so an existing site never drops back into the first-run dashboard if
 * its home page is unpublished later. Safe to run again.
 */
final class RecordPublishedSitesUpgradeStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.record-published-sites';
    }

    public function label(): string
    {
        return 'Record which sites are already live';
    }

    /** Only when a site with a home page has no go-live date yet. */
    #[Override]
    public function shouldRun(UpgradeContext $context): bool
    {
        return resolve(ReconcileSitePublicationMilestonesAction::class)->hasUnrecordedHomePages();
    }

    public function run(UpgradeContext $context): bool
    {
        ReconcileSitePublicationMilestonesAction::run();

        return true;
    }

    /** The milestones only stop an established site showing first-run guidance; there is nothing to undo. */
    #[Override]
    public function rollback(UpgradeContext $context): bool
    {
        return true;
    }
}
