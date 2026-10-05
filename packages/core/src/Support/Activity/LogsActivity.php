<?php

declare(strict_types=1);

namespace Capell\Core\Support\Activity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Activity;

/** @mixin Model */
trait LogsActivity
{
    use VendorLogsActivity;

    /** @return MorphMany<Activity, $this> */
    public function activities(): MorphMany
    {
        return $this->morphMany(ActivityLogCompat::activityModelClass(), 'subject');
    }

    public function beforeActivityLogged(Activity $activity, string $event): void
    {
        if (method_exists($this, 'tapActivity')) {
            $this->tapActivity($activity, $event);
        }
    }
}
