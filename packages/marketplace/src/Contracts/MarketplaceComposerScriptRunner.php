<?php

declare(strict_types=1);

namespace Capell\Marketplace\Contracts;

use Capell\Marketplace\Data\MarketplaceComposerResultData;

/**
 * Replays the host application's own Composer scripts after an install.
 *
 * The Marketplace requires packages with --no-scripts, which suppresses every
 * hook the application declares for post-autoload-dump — not only Laravel's
 * package:discover. Capell cannot know what those hooks are: the application
 * lives in a different repository and may register asset publishing, cache
 * warming, or anything else. So the state is restored by replaying whatever the
 * application itself declared, rather than by reproducing a list of hooks in
 * Capell.
 */
interface MarketplaceComposerScriptRunner
{
    public const string POST_AUTOLOAD_DUMP = 'post-autoload-dump';

    /**
     * Runs the named root-package script event.
     *
     * Returns null when the application declares no script for that event,
     * which is a legitimate shape rather than a failure.
     */
    public function replayRootScript(string $event, int $timeoutSeconds): ?MarketplaceComposerResultData;
}
