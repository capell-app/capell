<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Actions\Publishing\RunBulkPublicationTransitionAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Adapter: cancels future publish/unpublish schedules via the Core publication
 * state machine, mapping the typed outcomes back onto this action's long-standing
 * return shape so the Filament caller and its notification text stay stable.
 *
 * Authorization, trashed-record rejection and no-op detection all happen inside
 * Core and surface as typed outcomes — they are not re-derived here.
 */
class BulkCancelScheduleAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, Page&Pageable>  $pages
     * @return array{cancelled: int, skipped: int}
     */
    public function handle(Collection $pages, User $actor): array
    {
        $preview = RunBulkPublicationTransitionAction::run(
            records: $pages,
            actor: $actor,
            transition: PublicationTransition::CancelSchedule,
            now: CarbonImmutable::now(),
        );

        return [
            'cancelled' => $preview->changed(),
            'skipped' => $preview->blocked() + $preview->unchanged(),
        ];
    }
}
