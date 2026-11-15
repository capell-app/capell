<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Publishing\Review;

use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublishAbility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Returns a page in review to its author with a note. Only someone who may publish the page may request changes.
 *
 * Acts as the signed-in user: the review history records that user as the actor.
 * An invalid transition throws InvalidAggregateTransition with the reason.
 */
final class RequestPageReviewChangesAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page, string $note): void
    {
        $actor = Auth::user();

        if ($actor === null || ! PublishAbility::allows($actor, $page)) {
            throw new AuthorizationException(__('capell-core::message.page_review_not_allowed'));
        }

        PageAggregate::retrieve((string) $page->uuid)->requestChanges($note)->persist();
    }
}
