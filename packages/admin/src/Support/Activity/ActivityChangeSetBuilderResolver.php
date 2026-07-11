<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Activity;

use Capell\Admin\Contracts\Activity\ActivityChangeSetBuilder;
use Spatie\Activitylog\Models\Activity;

final class ActivityChangeSetBuilderResolver
{
    public function resolve(Activity $activity): ActivityChangeSetBuilder
    {
        $builders = collect(app()->tagged(ActivityChangeSetBuilder::TAG))
            ->filter(fn (object $builder): bool => $builder instanceof ActivityChangeSetBuilder)
            ->sortByDesc(fn (ActivityChangeSetBuilder $builder): int => $builder->priority());

        foreach ($builders as $builder) {
            if ($builder->supports($activity)) {
                return $builder;
            }
        }

        return resolve(DefaultActivityChangeSetBuilder::class);
    }
}
