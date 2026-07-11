<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Events;

use Carbon\CarbonImmutable;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Editorial intent: the page is scheduled to go live at a future time. The
 * projector mirrors this onto visible_from so the legacy publish-state
 * derivation keeps returning "scheduled" unchanged.
 */
final class PagePublishScheduled extends ShouldBeStored
{
    public function __construct(
        public readonly CarbonImmutable $at,
    ) {}
}
