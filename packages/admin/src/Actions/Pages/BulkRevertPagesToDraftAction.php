<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Actions\Publishing\RunBulkPublicationTransitionAction;
use Capell\Admin\Support\Publishing\PublicationSkipReason;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Adapter: reverts a collection of Page records back to draft via the Core
 * publication state machine, mapping the typed outcomes back onto this action's
 * long-standing return shape so the Filament caller and its notification text
 * stay stable.
 *
 * Authorization, trashed-record rejection and already-draft detection all happen
 * inside Core and surface as typed outcomes — they are not re-derived here.
 */
class BulkRevertPagesToDraftAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, Page&Pageable>  $pages
     * @return array{
     *     reverted: int,
     *     skipped: int,
     *     skipped_pages: list<array{id: int, name: string, reason: string}>
     * }
     */
    public function handle(Collection $pages, User $actor): array
    {
        $preview = RunBulkPublicationTransitionAction::run(
            records: $pages,
            actor: $actor,
            transition: PublicationTransition::RevertToDraft,
            now: CarbonImmutable::now(),
        );

        $skippedPages = [];

        foreach ($preview->records as $record) {
            $reason = PublicationSkipReason::for($record['result'], 'already_draft');

            if ($reason === null) {
                continue;
            }

            $skippedPages[] = [
                'id' => (int) $record['id'],
                'name' => $record['label'],
                'reason' => $reason,
            ];
        }

        return [
            'reverted' => $preview->changed(),
            'skipped' => $preview->blocked() + $preview->unchanged(),
            'skipped_pages' => $skippedPages,
        ];
    }
}
