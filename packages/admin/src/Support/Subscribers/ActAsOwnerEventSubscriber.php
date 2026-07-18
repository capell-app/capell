<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Subscribers;

use Capell\Admin\Actions\Users\RecordActAsOwnerActivityAction;
use Illuminate\Events\Dispatcher;
use STS\FilamentImpersonate\Events\EnterImpersonation;
use STS\FilamentImpersonate\Events\LeaveImpersonation;

final class ActAsOwnerEventSubscriber
{
    public function handleEnterImpersonation(EnterImpersonation $event): void
    {
        RecordActAsOwnerActivityAction::run(
            supportUser: $event->impersonator,
            ownerUser: $event->impersonated,
            event: RecordActAsOwnerActivityAction::EVENT_STARTED,
        );
    }

    public function handleLeaveImpersonation(LeaveImpersonation $event): void
    {
        RecordActAsOwnerActivityAction::run(
            supportUser: $event->impersonator,
            ownerUser: $event->impersonated,
            event: RecordActAsOwnerActivityAction::EVENT_STOPPED,
        );
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EnterImpersonation::class, self::class . '@handleEnterImpersonation');
        $events->listen(LeaveImpersonation::class, self::class . '@handleLeaveImpersonation');
    }
}
