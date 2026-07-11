<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Activity;

use Capell\Admin\Data\Activity\ActivityPresentationData;
use Spatie\Activitylog\Models\Activity;

interface ActivityDecorator
{
    public function supports(Activity $activity): bool;

    public function decorate(Activity $activity): ActivityPresentationData;
}
