<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;

/**
 * The five-way visibility state derived from a publishable record's
 * `visible_from` / `visible_until` dates and its trashed flag.
 *
 * Unlike {@see PublishStatusEnum} (which collapses every future publish date
 * into a single "pending" case), this enum distinguishes a genuine future
 * schedule from the far-future draft sentinel — see {@see PublishSentinel}
 * for the boundary math.
 */
enum PublishVisibilityStateEnum: string implements HasLabel
{
    case draft = 'draft';

    case scheduled = 'scheduled';

    case published = 'published';

    case expired = 'expired';

    case deleted = 'deleted';

    /**
     * Classify a record's visibility state from its raw dates.
     *
     * Precedence: deleted → expired → draft (sentinel) → scheduled → published.
     * A `visible_from` exactly at the draft boundary is a schedule, not a
     * draft (`greaterThan` semantics, matching {@see PublishSentinel}).
     */
    public static function fromDates(
        ?DateTimeInterface $visibleFrom,
        ?DateTimeInterface $visibleUntil,
        bool $trashed,
        ?CarbonImmutable $now = null,
    ): self {
        $reference = $now ?? CarbonImmutable::now();
        $from = $visibleFrom instanceof DateTimeInterface ? CarbonImmutable::instance($visibleFrom) : null;
        $until = $visibleUntil instanceof DateTimeInterface ? CarbonImmutable::instance($visibleUntil) : null;

        return match (true) {
            $trashed => self::deleted,
            $until instanceof CarbonImmutable && $until->lessThan($reference) => self::expired,
            PublishSentinel::isDraftValue($from, $reference) => self::draft,
            $from instanceof CarbonImmutable && $from->greaterThan($reference) => self::scheduled,
            default => self::published,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::draft => __('capell::generic.draft'),
            self::scheduled => __('capell::generic.scheduled'),
            self::published => __('capell::generic.published'),
            self::expired => __('capell::generic.expired'),
            self::deleted => __('capell::generic.deleted'),
        };
    }
}
