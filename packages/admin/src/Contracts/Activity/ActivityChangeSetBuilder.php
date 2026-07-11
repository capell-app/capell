<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Activity;

use Capell\Admin\Data\Activity\ActivityChangeSetData;
use Spatie\Activitylog\Models\Activity;

interface ActivityChangeSetBuilder
{
    public const string TAG = 'capell.admin.activity-change-set-builder';

    public function supports(Activity $activity): bool;

    public function priority(): int;

    public function build(Activity $activity): ActivityChangeSetData;
}
