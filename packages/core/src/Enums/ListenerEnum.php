<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum ListenerEnum: string
{
    case PackageInstalled = 'plugin.installed';

    case PackageUninstalled = 'plugin.uninstalled';

    // Editorial workflow + rollback hooks emitted by the event-sourcing reactor
    // so packages can react (cache eviction, redirect rebuild, beacon refresh)
    // without importing event-sourcing internals.
    case PagePublished = 'page.published';

    case PageUnpublished = 'page.unpublished';

    case PagePublishScheduled = 'page.publish_scheduled';

    case PageArchived = 'page.archived';

    case PageRolledBack = 'page.rolled_back';
}
