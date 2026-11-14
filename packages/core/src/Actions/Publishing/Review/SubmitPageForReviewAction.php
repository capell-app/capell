<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Publishing\Review;

use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\Models\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Sends a page to review. Anyone who can edit the page may submit it.
 *
 * Acts as the signed-in user: the review history records that user as the actor.
 * An invalid transition throws InvalidAggregateTransition with the reason.
 */
final class SubmitPageForReviewAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page): void
    {
        $actor = Auth::user();

        if ($actor === null || ! Gate::forUser($actor)->allows('update', $page)) {
            throw new AuthorizationException(__('capell-core::message.page_review_not_allowed'));
        }

        PageAggregate::retrieve((string) $page->uuid)->submitForReview()->persist();
    }
}
