<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Activity;

use Capell\Admin\Data\Activity\ActivityChangeSetData;
use Capell\Admin\Support\Activity\ActivityChangeSetBuilderResolver;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Activitylog\Models\Activity;

/**
 * @method static ActivityChangeSetData run(Activity $activity)
 */
final class BuildActivityChangeSetAction
{
    use AsObject;

    public function handle(Activity $activity): ActivityChangeSetData
    {
        $builder = resolve(ActivityChangeSetBuilderResolver::class)->resolve($activity);

        return $builder->build($activity);
    }
}
