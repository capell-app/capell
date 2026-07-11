<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Activity\ActivityChangeSetBuilder;
use Capell\Admin\Data\Activity\ActivityChangedFieldData;
use Capell\Admin\Data\Activity\ActivityChangedResourceData;
use Capell\Admin\Data\Activity\ActivityChangeSetData;
use Capell\Core\Models\Language;
use Spatie\Activitylog\Models\Activity;

final class PackageActivityChangeSetBuilderForListTest implements ActivityChangeSetBuilder
{
    public static int $buildCount = 0;

    public function supports(Activity $activity): bool
    {
        return true;
    }

    public function priority(): int
    {
        return 100;
    }

    public function build(Activity $activity): ActivityChangeSetData
    {
        self::$buildCount++;

        return new ActivityChangeSetData(
            summary: 'Package summary',
            resource: new ActivityChangedResourceData(
                morphType: $activity->subject_type,
                modelClass: Language::class,
                stableIdentifier: 'language:french',
                label: 'Package resource',
                url: null,
                area: 'Package area',
                package: 'capell/package-test',
                changedFieldCount: 1,
            ),
            fields: [
                new ActivityChangedFieldData(
                    path: 'name',
                    beforeValue: 'Francais',
                    afterValue: 'French',
                    status: 'updated',
                    reversible: true,
                    label: 'Package Headline',
                ),
            ],
            actorLabel: 'Package actor',
            event: $activity->event,
            occurredAt: $activity->created_at,
            workspaceId: null,
            emptyMessage: null,
        );
    }
}
