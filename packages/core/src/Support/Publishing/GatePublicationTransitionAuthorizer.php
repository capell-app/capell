<?php

declare(strict_types=1);

namespace Capell\Core\Support\Publishing;

use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Illuminate\Support\Facades\Gate;

/**
 * Putting a record live or taking it down needs the publish ability; returning it
 * to draft or cancelling a schedule only needs permission to edit it.
 */
final class GatePublicationTransitionAuthorizer implements AuthorizesPublicationTransition
{
    public function allows(PublicationTransitionRequestData $request): bool
    {
        return match ($request->transition) {
            PublicationTransition::PublishNow,
            PublicationTransition::SchedulePublish,
            PublicationTransition::Unpublish,
            PublicationTransition::ScheduleUnpublish => PublishAbility::allows($request->actor, $request->record),
            PublicationTransition::RevertToDraft,
            PublicationTransition::CancelSchedule => Gate::forUser($request->actor)->allows('update', $request->record),
        };
    }
}
