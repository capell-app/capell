<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Contracts;

use Capell\Core\Models\Page;

/**
 * Lets an optional package add the page state it owns to every page revision
 * and restore it when the page is rolled back.
 *
 * Tag an implementation with {@see self::TAG}. Its captured state is stored
 * under {@see self::key()} as a top-level section of the page snapshot, so the
 * rollback preview diffs it like any core section and rollback validators
 * registered for Page receive it in the target state.
 *
 * Compatibility: a snapshot recorded before a contributor existed has no
 * section for it. Restoring such a snapshot leaves the contributor's current
 * state untouched, and the preview does not report it as removed. A
 * contributor that changes its own section shape must keep restoring the
 * shapes it wrote before.
 */
interface PageStateContributor
{
    public const string TAG = 'capell.page-state-contributors';

    /**
     * The snapshot section this contributor owns. Must be unique and must not
     * reuse a core section name.
     */
    public function key(): string;

    /**
     * Capture the contributor's owned state for the page from the database.
     *
     * @return array<array-key, mixed>
     */
    public function capture(Page $page): array;

    /**
     * Replace the contributor's owned state for the page with the captured
     * state. Runs inside the rollback transaction with model events silenced,
     * like the core sections; it must not depend on model observers.
     *
     * @param  array<array-key, mixed>  $state
     */
    public function restore(Page $page, array $state): void;
}
