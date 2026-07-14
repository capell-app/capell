<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Pages;

use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Admin-side alias for the Core draft-sentinel date math.
 *
 * All the boundary rules live in {@see PublishSentinel}; this class keeps the
 * long-standing Admin API (and its call sites) stable while delegating every
 * decision to Core so the rule can never drift between packages.
 */
final class PagePublishSentinel
{
    /**
     * Years past "now" beyond which a future `visible_from` is treated as a
     * draft placeholder rather than a genuine scheduled publish date.
     *
     * @var int
     */
    public const DRAFT_BOUNDARY_YEARS = PublishSentinel::DRAFT_BOUNDARY_YEARS;

    /**
     * The far-future `visible_from` value written to mark a page as draft.
     */
    public static function draftValue(): CarbonImmutable
    {
        return PublishSentinel::draftValue();
    }

    /**
     * The cut-off date: a future `visible_from` beyond this is a draft
     * placeholder; on or before it (but still future) is a real schedule.
     */
    public static function draftBoundary(): CarbonImmutable
    {
        return PublishSentinel::draftBoundary();
    }

    /**
     * Whether the given `visible_from` is the far-future draft sentinel rather
     * than a genuine publish/schedule date.
     */
    public static function isDraftValue(?DateTimeInterface $visibleFrom): bool
    {
        return PublishSentinel::isDraftValue($visibleFrom);
    }
}
