<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Publishing;

enum PublicationTransition: string
{
    case CancelSchedule = 'cancel-schedule';
    case PublishNow = 'publish-now';
    case RevertToDraft = 'revert-to-draft';
    case SchedulePublish = 'schedule-publish';
    case ScheduleUnpublish = 'schedule-unpublish';
    case Unpublish = 'unpublish';

    public function requiresRequestedTime(): bool
    {
        return $this === self::SchedulePublish || $this === self::ScheduleUnpublish;
    }
}
