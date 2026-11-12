<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\FirstRunTaskContextData;
use Capell\Admin\Data\Dashboard\FirstRunTaskData;

/**
 * Adds tasks to the dashboard's getting-started checklist.
 *
 * Tag the implementation with {@see self::TAG}. Contributed tasks appear after
 * the built-in setup tasks and before "Publish your site", in tag order. Return
 * only tasks this actor can act on, work out `complete` from data the install
 * already holds, and give `url` only when the actor may open it. A key that is
 * already in use is skipped, and a contributor that throws is reported and
 * left out so the checklist still renders.
 *
 * tasks() runs on every dashboard render while a site is being set up, so keep
 * it to a few indexed queries for the context's site: no remote calls, no
 * scans across sites and no work that grows with content volume.
 *
 * @experimental
 */
interface FirstRunTaskContributor
{
    public const string TAG = 'capell.admin.first-run-tasks';

    /** @return list<FirstRunTaskData> */
    public function tasks(FirstRunTaskContextData $context): array;
}
