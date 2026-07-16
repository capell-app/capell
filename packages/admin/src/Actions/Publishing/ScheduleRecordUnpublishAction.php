<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Publishing;

use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Models\Contracts\Publishable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ScheduleRecordUnpublishAction
{
    use AsFake;
    use AsObject;

    public function handle(Model&Publishable $record, User $actor, CarbonImmutable $unpublishAt): PublicationTransitionResultData
    {
        $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
            record: $record,
            transition: PublicationTransition::ScheduleUnpublish,
            actor: $actor,
            now: CarbonImmutable::now(),
            requestedTime: $unpublishAt,
        ));

        if ($result->changed()) {
            RecordPublishHistoryAction::run($record, [
                'visible_until' => $result->visibleUntil?->toDateTimeString(),
                'scheduled_unpublish_by' => $actor->getKey(),
            ]);
        }

        return $result;
    }
}
