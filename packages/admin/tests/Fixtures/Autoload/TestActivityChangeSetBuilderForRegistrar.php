<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Activity\ActivityChangeSetBuilder;
use Capell\Admin\Data\Activity\ActivityChangeSetData;
use Spatie\Activitylog\Models\Activity;

final class TestActivityChangeSetBuilderForRegistrar implements ActivityChangeSetBuilder
{
    public function supports(Activity $activity): bool
    {
        return true;
    }

    public function priority(): int
    {
        return 0;
    }

    public function build(Activity $activity): ActivityChangeSetData
    {
        return new ActivityChangeSetData(
            summary: 'Test',
            resource: null,
            fields: [],
            actorLabel: 'System',
            event: $activity->event,
            occurredAt: $activity->created_at,
            workspaceId: null,
            emptyMessage: null,
        );
    }
}
