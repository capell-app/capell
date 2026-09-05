<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Actions\Publishing\TransitionPublicationAction;

/**
 * How strictly a property must be filled in before it counts as complete.
 *
 * `None` carries no completeness expectation. `Contract` marks the page
 * agent-layer-incomplete when missing but never blocks publishing — the
 * page still goes live, the gap is surfaced in the agent-schema report.
 * `Publish` hard-gates: {@see TransitionPublicationAction}
 * refuses to move the page into a published visibility window while any
 * `Publish`-required property is missing a value.
 *
 * Ordered `None < Contract < Publish` so a `locked` definition can express a
 * floor extensions/blueprints may raise but never lower ({@see self::atLeast()}).
 */
enum PropertyRequirement: string
{
    case None = 'none';
    case Contract = 'contract';
    case Publish = 'publish';

    /**
     * Whether this requirement level is at least as strict as the given floor.
     */
    public function atLeast(self $floor): bool
    {
        return $this->rank() >= $floor->rank();
    }

    /**
     * Clamp this requirement so it never drops below the given floor.
     */
    public function clampedTo(self $floor): self
    {
        return $this->atLeast($floor) ? $this : $floor;
    }

    private function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Contract => 1,
            self::Publish => 2,
        };
    }
}
