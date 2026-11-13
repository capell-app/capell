<?php

declare(strict_types=1);

namespace Capell\Core\Events\Capabilities;

use Capell\Core\Data\Capabilities\PublishingWorkspaceTransitionData;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An editorial workspace changed state; dispatched after the change commits.
 */
final readonly class PublishingWorkspaceTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public PublishingWorkspaceTransitionData $transition,
    ) {}
}
