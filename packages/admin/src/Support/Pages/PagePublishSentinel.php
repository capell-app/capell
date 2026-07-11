<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Pages;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Single source of truth for the "draft sentinel" date math used across the
 * page publishing flow.
 *
 * Capell has no boolean `is_draft` column. Instead a page's draft/scheduled/
 * published state is derived from `visible_from`:
 *
 *   - past or null          → published (live now)
 *   - future, within +50yr  → scheduled (a real publish date the editor chose)
 *   - future, beyond +50yr  → draft (a far-future placeholder, never meant to go live)
 *
 * Reverting to draft writes the far-future {@see self::draftValue()} sentinel;
 * the {@see self::DRAFT_BOUNDARY_YEARS} cut-off distinguishes that placeholder
 * from a genuine future schedule. Both the bulk actions, the single-record
 * actions, and the publish panel's status derivation lean on this class so the
 * rule can never drift between them.
 */
final class PagePublishSentinel
{
    /**
     * Years past "now" beyond which a future `visible_from` is treated as a
     * draft placeholder rather than a genuine scheduled publish date.
     *
     * @var int
     */
    public const DRAFT_BOUNDARY_YEARS = DefaultPageTableStatusResolver::DRAFT_SENTINEL_YEARS;

    /**
     * Extra years added on top of the boundary when *writing* the draft
     * sentinel, so a reverted page sits comfortably clear of the cut-off.
     *
     * @var int
     */
    private const DRAFT_WRITE_OFFSET_YEARS = 50;

    /**
     * The far-future `visible_from` value written to mark a page as draft.
     */
    public static function draftValue(): CarbonImmutable
    {
        return CarbonImmutable::now()->addYears(self::DRAFT_BOUNDARY_YEARS + self::DRAFT_WRITE_OFFSET_YEARS);
    }

    /**
     * The cut-off date: a future `visible_from` beyond this is a draft
     * placeholder; on or before it (but still future) is a real schedule.
     */
    public static function draftBoundary(): CarbonImmutable
    {
        return CarbonImmutable::now()->addYears(self::DRAFT_BOUNDARY_YEARS);
    }

    /**
     * Whether the given `visible_from` is the far-future draft sentinel rather
     * than a genuine publish/schedule date.
     */
    public static function isDraftValue(?DateTimeInterface $visibleFrom): bool
    {
        return $visibleFrom instanceof DateTimeInterface
            && CarbonImmutable::instance($visibleFrom)->greaterThan(self::draftBoundary());
    }
}
