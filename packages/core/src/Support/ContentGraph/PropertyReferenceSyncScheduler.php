<?php

declare(strict_types=1);

namespace Capell\Core\Support\ContentGraph;

use Capell\Core\Actions\ContentGraph\SyncPropertyReferenceGraphEdgesAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Term;

/**
 * Queues one property-reference re-index per owner for the end of the request, job or command, so
 * many value changes in one unit of work collapse into a single sync that reads the final state.
 */
final class PropertyReferenceSyncScheduler
{
    /** @param class-string<Page|Term> $ownerType */
    public static function schedule(string $ownerType, int $ownerId): void
    {
        $isPage = is_a($ownerType, Page::class, true);

        defer(
            static function () use ($isPage, $ownerId): void {
                // A trashed page keeps its references so a restore brings them back.
                $owner = $isPage
                    ? Page::query()->withTrashed()->find($ownerId)
                    : Term::query()->find($ownerId);

                if ($owner instanceof Page || $owner instanceof Term) {
                    SyncPropertyReferenceGraphEdgesAction::run($owner);
                }
            },
            'content-graph:property-references:' . ($isPage ? Page::class : Term::class) . ':' . $ownerId,
            always: true,
        );
    }
}
