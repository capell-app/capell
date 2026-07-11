<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Activity;

use Capell\Admin\Enums\CapellPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Activitylog\Models\Activity;

/**
 * @method static bool run(Activity $activity)
 */
final class DeleteActivityLogAction
{
    use AsObject;

    public function handle(Activity $activity): bool
    {
        throw_unless(
            auth()->user()?->can(CapellPermission::DeleteActivityLog->name()) === true,
            AuthorizationException::class,
        );

        $snapshot = [
            'deleted_activity_id' => $activity->getKey(),
            'deleted_activity_description' => $activity->description,
            'deleted_activity_event' => $activity->event,
            'deleted_activity_log_name' => $activity->log_name,
            'deleted_activity_subject_id' => $activity->subject_id,
            'deleted_activity_subject_type' => $activity->subject_type,
            'deleted_activity_causer_id' => $activity->causer_id,
            'deleted_activity_causer_type' => $activity->causer_type,
            'deleted_activity_created_at' => $activity->created_at?->toISOString(),
        ];

        return DB::transaction(function () use ($activity, $snapshot): bool {
            $deleted = (bool) $activity->delete();

            if (! $deleted) {
                return false;
            }

            activity()
                ->causedBy(auth()->user())
                ->event('deleted')
                ->withProperties($snapshot)
                ->log('deleted activity log entry');

            return true;
        });
    }
}
