<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extensions;

use Capell\Admin\Data\Extensions\ExtensionRemovalOutcomeData;
use Capell\Admin\Data\Extensions\ExtensionRemovalRequestData;
use Capell\Admin\Enums\Extensions\ExtensionRemovalMode;

/**
 * Whatever performs extension removals on this site.
 *
 * The seam exists so the admin panel can hand a removal to the Marketplace
 * queue without knowing that the Marketplace exists. Admin must not depend on
 * Marketplace — the bridge is the whole reason that rule holds — so the panel
 * asks this interface which mode applies and does as it is told. The default
 * implementation, and the only one shipped by admin itself, answers "in this
 * request", which is what a site with no marketplace package has always done.
 */
interface ExtensionRemovalCoordinator
{
    /**
     * How this site would perform a removal of $composerName right now.
     *
     * Asked per package rather than once per host: a host may be able to
     * automate removals in general and still be unable to remove this
     * particular extension, and the modal has to say which.
     */
    public function modeFor(string $composerName): ExtensionRemovalMode;

    /**
     * What an operator on a manual-only host should run, and where.
     *
     * Only meaningful for ExtensionRemovalMode::ManualInstructions.
     */
    public function manualInstructions(string $composerName, string $extensionName): string;

    /**
     * Hand the removal over. The outcome reports whether it was accepted, not
     * whether it finished.
     *
     * Only meaningful for ExtensionRemovalMode::Queued.
     */
    public function queue(ExtensionRemovalRequestData $request): ExtensionRemovalOutcomeData;
}
