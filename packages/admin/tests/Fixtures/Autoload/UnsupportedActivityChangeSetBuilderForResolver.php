<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Activity\ActivityChangeSetBuilder;
use Capell\Admin\Data\Activity\ActivityChangeSetData;
use Spatie\Activitylog\Models\Activity;

final class UnsupportedActivityChangeSetBuilderForResolver implements ActivityChangeSetBuilder
{
    public function priority(): int
    {
        return 0;
    }

    public function supports(Activity $activity): bool
    {
        return false;
    }

    public function build(Activity $activity): ActivityChangeSetData
    {
        return activityChangeSetForResolver($activity, 'Unsupported change set');
    }
}
