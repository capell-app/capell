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
use Lorisleiva\Actions\Concerns\AsObject;

final class UnpublishRecordAction
{
    use AsObject;

    public function handle(Model&Publishable $record, User $actor): PublicationTransitionResultData
    {
        $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
            record: $record,
            transition: PublicationTransition::Unpublish,
            actor: $actor,
            now: CarbonImmutable::now(),
        ));

        if ($result->changed()) {
            RecordPublishHistoryAction::run($record, [
                'visible_until' => $result->visibleUntil?->toDateTimeString(),
                'unpublished_by' => $actor->getKey(),
            ]);
        }

        return $result;
    }
}
